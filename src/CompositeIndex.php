<?php
namespace Wpup;

class CompositeIndex {
	private $columnCount;
	private $values = [];

	public function __construct($columnCount) {
		if ($columnCount < 2) {
			throw new \LogicException('The index must have at least 2 columns.');
		}
		$this->columnCount = $columnCount;
	}

	public function add(...$columnValues) {
		if (count($columnValues) < $this->columnCount) {
			throw new \RuntimeException(sprintf(
				'Too few arguments. Received: %d, expected: %d.',
				count($columnValues),
				$this->columnCount
			));
		}

		$array = &$this->values;
		for($index = 0; $index < $this->columnCount - 2; $index++) {
			$key = $columnValues[$index];
			if (!isset($array[$key])) {
				$array[$key] = [];
			}
			$array = &$array[$key];
		}

		$array[$columnValues[$this->columnCount - 2]] = $columnValues[$this->columnCount - 1];
	}

	public function rows($depth = null) {
		if (!isset($depth)) {
			$depth = $this->columnCount;
		}
		if ($depth === 0) {
			yield $this->values;
			return;
		}
		if ($depth > $this->columnCount) {
			throw new \OutOfBoundsException();
		}

		//The "yield from" construct from PHP 7 would be really handy here.
		//Basically, this is a recursive iterator with a manually managed stack.
		$stack = [$this->values];
		$index = 0;
		reset($stack[$index]);
		$row = [];

		while(true) {
			$key = key($stack[$index]);
			if ($key === null) {
				if ($index <= 0) {
					break;
				} else {
					$index--;
					next($stack[$index]);
					continue;
				}
			}

			$row[$index] = $key;
			$value = current($stack[$index]);
			if (($index >= $depth - 1) || !is_array($value)) {
				$row[$index + 1] = $value;
				yield $row;
				next($stack[$index]);
			} else {
				$index++;
				$stack[$index] = $value;
			}
		}
	}

}