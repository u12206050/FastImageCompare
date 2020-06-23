<?php
/**
 * (c) Paweł Plewa <pawel.plewa@gmail.com> 2018
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 */


namespace pepeEpe\FastImageCompare;

use FastImageSize\FastImageSize;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Class FastImageCompare
 *
 * By default it calculates difference of images using Mean Absolute Error metric ( MAE )
 *
 *
 * @package pepeEpe
 */
class FastImageCompare
{

    const PREFER_ANY = 1;
    const PREFER_LARGER_IMAGE = 2;
    const PREFER_SMALLER_IMAGE = 4;
    const PREFER_LOWER_DIFFERENCE = 8;
    const PREFER_LARGER_DIFFERENCE = 16;
    const PREFER_COLOR = 32;
    const PREFER_GRAYSCALE = 64;

    /**
     * @var CacheItemPoolInterface
     */
    private $cacheAdapter;

    /**
     * @var bool
     */
    private $debugEnabled = false;

    /**
     * @var callable
     */
    private $logger = null;

    /**
     * @var
     */
    private $temporaryDirectory;

    /**
     * @var int
     */
    private $temporaryDirectoryPermissions = 0777;

    /**
     * @var null
     */
    static $imageSizerInstance = null;

    /**
     * @var IComparable[]
     */
    private $registeredComparators = [];

    /**
     * @var IClassificable[]
     */
    private $registeredClassifiers = [];

    /**
     * @var int
     */
    private $chunkSize = 8;

    /**
     * @var array
     */
    private $classifierData = [];

    /**
     * FastImageCompare constructor.
     *
     *
     * @param null $absoluteTemporaryDirectory When null, library will use system temporary directory
     * @param IComparable[]|IComparable|null $comparators comparator instance(s), when null - no comparators will be registered, when empty array a default comparator will be registered @see ComparatorImageMagick with metric MEAN ABSOLUTE ERROR
     * @param $cacheAdapter AdapterInterface
     */
    public function __construct($absoluteTemporaryDirectory = null, $comparators = [], $cacheAdapter = null)
    {
        $this->setTemporaryDirectory($absoluteTemporaryDirectory);
        $this->setCacheAdapter($cacheAdapter);

        if (is_null($comparators)) {

        } elseif (is_array($comparators)) {
            //set array of comparators
            if (count($comparators) == 0) {
                $this->registerComparator(new ComparatorImageMagick(ComparatorImageMagick::METRIC_MAE));
            } else {
                $this->setComparators($comparators);
            }
        } elseif ($comparators instanceof IComparable){
            //register
            $this->registerComparator($comparators);
        }
    }

    public static function cacheIt($files, $ns, $cacheAdapter) {
        foreach ($files as $filePath) {
            $key = $ns.'.'.md5($filePath);
            $item = $cacheAdapter->getItem($key);
            if (!$item->isHit()) {
                $result = hash('crc32b',file_get_contents($filePath));
                $item->set($result);
                $cacheAdapter->save($item);
            }
        }
    }

    private function warmUpCache(array $inputImages) {
        $chunks = array_chunk($inputImages, $this->getChunkSize(), true);
        $totalChunks = count($chunks);

        $threads = [];
        $totalThreads = $totalChunks < 8 ? $totalChunks : 8;
        for ($i=0; $i < $totalThreads; ++$i) {
            array_push($threads, new Thread(array($this, 'cacheIt')));
        }

        $ns = Utils::getClassNameWithoutNamespace($this);
        $cacheAdapter = $this->getCacheAdapter();

        $nextChunk = 0;
        $isAlive = $totalThreads;
        while ($isAlive) {
            $this->printDebug("Processing threads", "$isAlive/$totalThreads");
            $isAlive = 0;
            foreach ($threads as $thread) {
                if ($thread->isAlive()) {
                    ++$isAlive;
                } else {
                    if ($nextChunk < $totalChunks) {
                        $thread->start($chunks[$nextChunk], $ns, $cacheAdapter);
                        $this->printDebug("Processing chunk", (++$nextChunk) . "/$totalChunks");
                        ++$isAlive;
                    }
                }
            }
            if ($isAlive) sleep(ceil($isAlive / 2));
        }
    }

    /**
     * Compares each with each using registered comparators and return difference percentage in range 0..1
     * @param array $inputImages
     * @param $enoughDifference float
     * @return array
     */
    private function compareArray(array $inputImages,$enoughDifference)
    {
        $inputImages = array_unique($inputImages);
        $output = [];
        $imageNameKeys = array_keys($inputImages);

        if ($this->getCacheAdapter()) {
            $this->printDebug("Cache", "Warming up...");
            $this->warmUpCache($inputImages);
            $this->printDebug("Cache", "Finished. Continuing...");
        }

        //compare each with each
        $totalImages = count($inputImages);
        for ($x = 0; $x < $totalImages - 1; ++$x) {
            $this->printDebug("Processing", ($x + 1) . "/$totalImages");
            for ($y = $x + 1; $y < $totalImages; ++$y) {
                $leftInput = $inputImages[$imageNameKeys[$x]];
                $rightInput = $inputImages[$imageNameKeys[$y]];
                $compareResult = $this->internalCompareImage($leftInput, $rightInput,$enoughDifference);
                if ($compareResult <= $enoughDifference) {
                    $output[] = [$leftInput, $rightInput, $compareResult];
                }
            }
        }
        return $output;
    }


    private function classify(array $inputImages)
    {
        $output = [];
        foreach ($inputImages as $inputImage) {
            if (!isset($output[$inputImage])) $output[$inputImage] = [];
            foreach ($this->registeredClassifiers as $classifier) {
                $output[$inputImage] = array_merge($output[$inputImage], $classifier->classify($inputImage, $this));
            }
        }
        $this->classifierData = $output;
        //return array_unique($output);
    }


    /**
     * Internal method to compare images by registered comparators
     *
     * @param $inputLeft string
     * @param $inputRight string
     * @param float $enoughDifference
     * @return float
     */
    private function internalCompareImage($inputLeft, $inputRight, $enoughDifference)
    {
        $comparatorsSummary = 0.0;
        $comparatorsSummarizedInstances = 0;
        foreach ($this->registeredComparators as $comparatorIndex => $comparatorInstance)
        {

            $calculatedDifference = $comparatorInstance->difference($inputLeft,$inputRight,$enoughDifference,$this);

            /**
             * jesli komparator dziala w trybie dokladnym @see IComparable::STRICT, tzn ze jesli znajdzie roznice to nie trzeba dalej porownywac
             * i moze zostac zwrocony wynik , w przeciwnym wypadku niech kontynuuje [PASSTHROUGH] i sprawdza nastepne
             * komparatory
             */
            if ($comparatorInstance->getComparableMode() == IComparable::STRICT && $calculatedDifference <= $enoughDifference)
            {
                return $calculatedDifference;
            }
            /**
             * Jesli przekazujemy dalej , musimy wziac pod uwage wszystkie komparatory i na podstawie wyniku ze wszystkich
             * zadecydowac czy jest rowny czy rozny
             */

            if ($comparatorInstance->getComparableMode() != IComparable::STRICT)
            {
                $comparatorsSummary += $calculatedDifference;
                $comparatorsSummarizedInstances++;
            }
        }

        /**
         * return avg from non STRICT comparators
         */
        return ($comparatorsSummary > 0 && $comparatorsSummarizedInstances > 0) ? floatval($comparatorsSummary) / floatval($comparatorsSummarizedInstances) : $comparatorsSummary;
    }


    /**
     * @param $imageA
     * @param $imageB
     * @param float $enoughDifference
     * @return bool
     */
    public function areSimilar($imageA,$imageB,$enoughDifference = 0.05){
        return (count($this->findDuplicates([$imageA,$imageB],$enoughDifference)) == 2);
    }

    /**
     * @param $imageA
     * @param $imageB
     * @param float $enoughDifference
     * @return bool
     */
    public function areDifferent($imageA,$imageB,$enoughDifference = 0.05){
        return !$this->areSimilar($imageA,$imageB,$enoughDifference);
    }

    /**
     * @param array $inputImages
     * @param float $enough percentage 0..1
     * @return array
     */
    public function findDuplicates(array $inputImages, $enough = 0.05, $group = false)
    {
        $inputImages = array_unique($inputImages);
        $this->classify($inputImages);
        $compared = $this->compareArray($inputImages,$enough);

        $output = [];
        $groups = [];

        foreach ($compared as $data) {
            if ($data[2] <= $enough) {
                if ($group) {
                    $key1 = $data[0];
                    $key2 = $data[1];
                    if (isset($groups[$key1]) && isset($groups[$key2])) {
                        continue;
                    } else if (!isset($groups[$key1]) && !isset($groups[$key2])) {
                        $output[] = $groups[$key1] = $groups[$key2] = [
                            $key1, $key2
                        ];
                    } else if (isset($groups[$key1])) {
                        array_push($groups[$key1], $key2);
                        $groups[$key2] = $groups[$key1];
                    } else if (isset($groups[$key2])) {
                        array_push($groups[$key2], $key1);
                        $groups[$key1] = $groups[$key2];
                    }
                } else {
                    $output[] = $data[0];
                    $output[] = $data[1];
                }
            }
        }

        if ($group) {
            return $output;
        } else {
            sort($output);
            return array_unique($output);
        }
    }

    /**
     * @param array $images
     * @param float $enoughDifference
     * @param int $preferOnDuplicate
     * @return array
     */
    public function findUniques(array $images, $enoughDifference = 0.05, $preferOnDuplicate = FastImageCompare::PREFER_LARGER_IMAGE)
    {
        //TODO $matchMode bit flags
//        if ($matchMode & PREFER_LARGER_IMAGE) {
//            echo "PREFER_LARGER_IMAGE is set\n";
//        }

        //find duplicates
        $duplicatesMap = $this->extractDuplicatesMap($images, $enoughDifference);
        $duplicates = array_keys($duplicatesMap);
        //remove all duplicates from input
        $withoutDuplicates = array_diff($images, $duplicates);
        //add one duplicate based on fight
        $picked = [];
        foreach ($duplicates as $duplicate) {
            $dupFromMap = $duplicatesMap[$duplicate];
            //if not already picked up , pick only one duplicate
            if (!in_array($duplicate, $picked)) {
                $keys = array_keys($dupFromMap);
                $diff = array_intersect($keys, $picked);
                if (count($diff) == 0) {
                    $picked[] = $this->preferredPick($duplicatesMap, $duplicate, $preferOnDuplicate);
                }
            }
        }
        $s =  array_merge($picked, $withoutDuplicates);
        sort($s);
        return $s;
    }


    /**
     * Return duplicate map
     * eg. [img1] => [dup1,dup2], [dup1] => [img1,dup2] etc .
     * @param array $inputImages
     * @param float $enoughDifference
     * @return array
     */
    public function extractDuplicatesMap(array $inputImages, $enoughDifference = 0.05)
    {
        //TODO implement better chunking , recursive
        $inputImages = array_unique($inputImages);
        $this->classify($inputImages);
        $output = [];
        $chunks = array_chunk($inputImages,$this->getChunkSize(),true);
        $chunkedArray = [];
        $total = count($chunks);
        $needRechunk = $total > 1;

        foreach ($chunks as $index => $chunk) {
            $this->printDebug("Processing chunk", "$index/$total");
            $compared = $this->compareArray($chunk, $enoughDifference);
            foreach ($compared as $data)
            {
                if (!$needRechunk) {
                    if ($data[2] <= $enoughDifference) {
                        if (!isset($output[$data[0]])) $output[$data[0]] = array();
                        if (!isset($output[$data[1]])) $output[$data[1]] = array();
                        $output[$data[0]][$data[1]] = $data[2];
                        $output[$data[1]][$data[0]] = $data[2];
                    }
                } else {
                    $chunkedArray[] = $data[0];
                    $chunkedArray[] = $data[1];
                }
            }
        }

        if ($needRechunk){
            $output = [];
            $chunkedArray = array_unique($chunkedArray);
            $compared = $this->compareArray($chunkedArray, $enoughDifference);
            foreach ($compared as $data) {
                if ($data[2] <= $enoughDifference) {
                    if (!isset($output[$data[0]])) $output[$data[0]] = array();
                    if (!isset($output[$data[1]])) $output[$data[1]] = array();
                    $output[$data[0]][$data[1]] = $data[2];
                    $output[$data[1]][$data[0]] = $data[2];
                }
            }
        }

        $this->printDebug('extractDuplicatesMap',$output);
        return $output;
    }

    /**
     * @param $duplicateMap
     * @param $duplicateItem
     * @param int $preferOnDuplicate
     * @return int|null|string
     */
    private function preferredPick($duplicateMap, $duplicateItem, $preferOnDuplicate = FastImageCompare::PREFER_LARGER_IMAGE)
    {
        $mapEntry = $duplicateMap[$duplicateItem];
        switch ($preferOnDuplicate) {
            case self::PREFER_LARGER_DIFFERENCE:
                //add $duplicate to $mapEntry with maximum difference in $mapEntry
                $maxDiff = 0;
                foreach ($mapEntry as $entry => $differenceValue)
                    if ($entry === $duplicateItem)
                        $maxDiff = max($maxDiff, $differenceValue);
                $mapEntry[$duplicateItem] = $maxDiff;
                $sorted = ($mapEntry);
                arsort($sorted);
                reset($sorted);
                return key($sorted);
                break;
            case self::PREFER_LOWER_DIFFERENCE:
                $maxDiff = PHP_INT_MAX;
                foreach ($mapEntry as $entry => $differenceValue)
                    if ($entry === $duplicateItem)
                        $maxDiff = min($maxDiff, $differenceValue);

                $mapEntry[$duplicateItem] = $maxDiff;
                $sorted = ($mapEntry);
                asort($sorted);
                reset($sorted);
                return key($sorted);
                break;

            case self::PREFER_COLOR:
            case self::PREFER_GRAYSCALE:
                $values = array_keys($mapEntry);
                array_push($values, $duplicateItem);
                $values = array_unique($values);

                $tmpClassifier = new ClassifierColor();

                foreach ($values as $imagePath) {
                    $classifierTags = $tmpClassifier->classify($imagePath, $this);
                    if ($preferOnDuplicate == self::PREFER_COLOR && in_array(ClassifierColor::COLORS_COLOR, $classifierTags)) return $imagePath;
                    if ($preferOnDuplicate == self::PREFER_GRAYSCALE && in_array(ClassifierColor::COLORS_GRAYSCALE, $classifierTags)) return $imagePath;
                }
                return $duplicateItem;
                break;

            case self::PREFER_LARGER_IMAGE:
            case self::PREFER_SMALLER_IMAGE:
                $values = array_keys($mapEntry);
                array_push($values, $duplicateItem);
                $values = array_unique($values);
                $output = array();
                foreach ($values as $imagePath) {
                    $size = $this->getImageSize($imagePath);
                    if ($size) {
                        $output[$imagePath] = $size['width'] * $size['height'];
                    } else {
                        $output[$imagePath] = 0;
                    }
                }
                if ($preferOnDuplicate == self::PREFER_LARGER_IMAGE) {
                    arsort($output);
                } else {
                    asort($output);
                }
                reset($output);
                return key($output);
                break;
            default:
                return $duplicateItem;// or key($mapEntry);
        }
    }


    /**
     * Clears files in cache folder older than $lifeTimeSeconds,
     * @param int $lifeTimeSeconds , set null to remove all files
     * @param bool $clearCacheAdapter
     */
    public function clearCache($lifeTimeSeconds = null, $clearCacheAdapter = true)
    {
        $oldCache = Utils::getFilesOlderBy($this->getTemporaryDirectory(),$lifeTimeSeconds);
        Utils::removeFiles($oldCache);
        if (!is_null($this->getCacheAdapter()) && $clearCacheAdapter) $this->getCacheAdapter()->clear();
    }

    /**
     * SETTERS & GETTERS
     */


    public function getTemporaryDirectory()
    {
        return $this->temporaryDirectory;
    }

    /**
     * @param $directory
     * @throws \Exception
     */
    public function setTemporaryDirectory($directory)
    {
        if (is_null($directory)) {
            $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '_fastImageCompare' . DIRECTORY_SEPARATOR;
        } else {
            $this->temporaryDirectory = $directory . DIRECTORY_SEPARATOR . '_fastImageCompare' . DIRECTORY_SEPARATOR;
        }
        if (!file_exists($this->getTemporaryDirectory())) {
            mkdir($this->getTemporaryDirectory(), $this->getTemporaryDirectoryPermissions(), true);
            // it seems that vagrant has problems with setting permissions when creating directory so lets chmod it again
            @chmod($this->getTemporaryDirectory(), $this->getTemporaryDirectoryPermissions());
        }
        if (!is_writable($this->getTemporaryDirectory())) {
            throw new \Exception('Temporary directory ' . $this->getTemporaryDirectory() . ' is not writable');
        }
    }


    /**
     * @return FastImageSize|null
     */
    private function getImageSizerInstance()
    {
        if (is_null(self::$imageSizerInstance)) self::$imageSizerInstance = new FastImageSize();
        return self::$imageSizerInstance;
    }


    /**
     * @return int
     */
    public function getTemporaryDirectoryPermissions()
    {
        return $this->temporaryDirectoryPermissions;
    }

    /**
     * @param int $temporaryDirectoryPermissions
     */
    public function setTemporaryDirectoryPermissions($temporaryDirectoryPermissions)
    {
        $this->temporaryDirectoryPermissions = $temporaryDirectoryPermissions;
    }

    /**
     * @param IClassificable $classifier
     */
    public function registerClassifier(IClassificable $classifier)
    {
        $this->registeredClassifiers[] = $classifier;
    }

    /**
     * @param IClassificable[] $classifiers
     */
    public function setClassifiers(array $classifiers)
    {
        $this->registeredClassifiers = $classifiers;
    }

    /**
     * @return IClassificable[]
     */
    public function getClassifiers()
    {
        return $this->registeredClassifiers;
    }


    /**
     * Register new comparator with default mode @see IComparable::PASSTHROUGH constants
     * @param IComparable $comparatorInstance
     * @param int $mode IComparable mode
     */
    public function registerComparator(IComparable $comparatorInstance, $mode = IComparable::PASSTHROUGH)
    {
        $this->registeredComparators[] = $comparatorInstance;
        $comparatorInstance->setComparableMode($mode);
    }

    /**
     * @param IComparable[] $comparators
     */
    public function setComparators(array $comparators)
    {
        $this->registeredComparators = $comparators;
    }

    /**
     * @return IComparable[]
     */
    public function getComparators()
    {
        return $this->registeredComparators;
    }

    /**
     * Clear comparators
     */
    public function clearComparators()
    {
        $this->setComparators([]);
    }

    /**
     * @return int
     */
    public function getChunkSize()
    {
        return $this->chunkSize;
    }

    /**
     * @param int $chunkSize
     */
    public function setChunkSize($chunkSize)
    {
        $this->chunkSize = $chunkSize;
    }




    /**
     * @return bool
     */
    public function isDebugEnabled()
    {
        return $this->debugEnabled;
    }

    /**
     * @param bool $debugEnabled
     */
    public function setDebugEnabled($debugEnabled)
    {
        $this->debugEnabled = $debugEnabled;
    }

    public function setLogger($callable) {
        $this->logger = $callable;
    }

    /**
     * @return CacheItemPoolInterface
     */
    public function getCacheAdapter()
    {
        return $this->cacheAdapter;
    }

    /**
     * @param CacheItemPoolInterface $cacheAdapter
     */
    public function setCacheAdapter(CacheItemPoolInterface $cacheAdapter = null)
    {
        $this->cacheAdapter = $cacheAdapter;
    }


    /**
     * UTILS
     */

    /**
     * @param $imagePath
     * @return array|bool
     */
    public function getImageSize($imagePath)
    {
        return $this->getImageSizerInstance()->getImageSize($imagePath);
    }

    private function printDebug($label,$data)
    {
        if (!$this->isDebugEnabled()) {
            if ($this->logger) {
                try {
                    call_user_func($this->logger, "$label: $data");
                } catch (\Throwable $err) {}
            }
            return;
        }
        echo "<br/><b>$label</b>";
        if (!is_null($data))
        dump($data);
        echo '<br/>';
    }

    public static function debug(array $input)
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        echo '<hr>';
        foreach ($input as $img) {
            $url = '/'.str_replace($root, '', $img);
           // $b = basename($img);
           // $url = '/temporary/_importer_api/'.$b;
            echo '<img style="height:40px;padding:4px;" src="' . $url . '"/>';//<br/>';
        }
    }

}