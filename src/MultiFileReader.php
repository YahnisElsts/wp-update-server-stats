<?php
namespace Wpup;

/**
 * This class lets you read a group of files as if it was one big text file.
 *
 * @package Wpup
 */
class MultiFileReader {
	/**
	 * @var \SplFileObject[]
	 */
	private $files = [];

	/**
	 * @var int[] File offsets in the virtual stream.
	 */
	private $offsets = [];

	/**
	 * @var \SplFileObject
	 */
	private $currentFile = null;

	/**
	 * @var int Current file index in the $files array.
	 */
	private $fileIndex = 0;

	/**
	 * @var int Virtual file size.
	 */
	private $size = 0;

	public function __construct($files) {
		if (empty($files)) {
			throw new \RuntimeException('You must specify at least one file.');
		}

		$totalSize = 0;
		foreach($files as $fileName) {
			$object = new \SplFileObject($fileName);

			//Skip empty files.
			if ($object->getSize() === 0) {
				continue;
			}

			$this->files[] = $object;
			$this->offsets[] = $totalSize;

			$totalSize += $object->getSize();
		}

		$this->size = $totalSize;
		if (!empty($this->files)) {
			$this->currentFile = reset($this->files);
		}
	}

	/**
	 * Read the next line.
	 *
	 * @return bool|string
	 */
	public function fgets() {
		if (empty($this->files)) {
			return false;
		}

		if ($this->currentFile->eof() && $this->hasNextFile()) {
			//Move on to the next file.
			$this->fileIndex++;
			$this->currentFile = $this->files[$this->fileIndex];
			$this->currentFile->fseek(0, SEEK_SET);
		}

		return $this->currentFile->fgets();
	}

	/**
	 * @param int $offset
	 * @param int $whence
	 * @return int
	 */
	public function fseek($offset, $whence = SEEK_SET) {
		if (empty($this->files)) {
			return -1;
		}

		switch($whence) {
			case SEEK_END:
				$offset = $this->size + $offset;
				break;
			case SEEK_CUR:
				$offset = $this->ftell() + $offset;
				break;
		}

		if ($offset < 0) {
			return -1;
		}

		$foundIndex = 0;
		for ($index = count($this->offsets) - 1; $index > 0; $index--) {
			if ($this->offsets[$index] <= $offset) {
				$foundIndex = $index;
				break;
			}
		}

		$this->currentFile = $this->files[$foundIndex];
		$this->fileIndex = $foundIndex;
		$offsetInFile = $offset - $this->offsets[$foundIndex];

		return $this->currentFile->fseek($offsetInFile, SEEK_SET);
	}

	/**
	 * @return int
	 */
	public function ftell() {
		if (empty($this->files)) {
			return 0;
		}
		return $this->offsets[$this->fileIndex] + $this->currentFile->ftell();
	}

	/**
	 * @return bool
	 */
	public function feof() {
		if (empty($this->files)) {
			return true;
		}
		return !$this->hasNextFile() && $this->currentFile->eof();
	}

	private function hasNextFile() {
		return !empty($this->files) && ($this->fileIndex < (count($this->files) - 1));
	}
}