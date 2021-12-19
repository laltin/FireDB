<?php
require_once(__DIR__ . '/Medoo/Medoo.php');

class FireDB {
	private const MAX_DEPTH = 10;
	private const MAX_VARCHAR_LEN = 255;

	private $db;
	private $table;

	/**
	 *	```
	 *	$db = new FireDB([
	 *	    'type' => 'mysql',
 	 *	    'host' => 'localhost',
	 *	    'username' => 'root',
	 *	    'password' => '',
	 *	    'database' => 'project-db'
	 *	], 'json');
	 *	```
	 */
	public function __construct($medoo_options, $table) {
		$this->db = new Medoo\Medoo($medoo_options);
		$this->table = $table;
	}

	private function parsePath($path) {
		if (!is_string($path)) {
			throw new Exception("Invalid FireDB path");
		}

		$path = explode('/', $path);
		foreach ($path as $child) {
            // child can't be empty and can't contain anything except allowed chars
			if (!preg_match("/^[a-zA-Z0-9-_@]+$/", $child)) {
	            throw new Exception("Invalid FireDB path");
			}
        }

        $depth = count($path);
		if ($depth > self::MAX_DEPTH) {
			throw new Exception("FireDB path is deeper than allowed max");
		}

        return $path;
	}

	public function get($pathstr) {
		$path = $this->parsePath($pathstr);
		$depth = count($path);

		$where = [];
		for ($i = 0; $i < $depth; $i++) {
			$where["path$i"] = $path[$i];
		}

		// reconstruct object
		$obj = null;

		$this->db->select($this->table, "*", $where, function($row) use (&$obj, $depth) {
			$child = &$obj;

			for ($i = $depth; isset($row["path$i"]); $i++) {
				$key = $row["path$i"];

				if (!isset($child)) {
					$child = [
						$key => null
					];
				}
				$child = &$child[$key];
			}

			$type = $row["type"];
			$child = $row[$type . "_value"];

			if ($type == 'bool') {
				// convert value to bool
				$child = $child ? true : false;
			}
			else if ($type == 'int') {
				// convert value to int
				$child = (int)$child;
			}
		});

		return $obj;
	}

	private function generateRowInsert($pathstr, $value) {
		$path = $this->parsePath($pathstr);
		$depth = count($path);

		$insert = [
			'bool_value' => null,
			'int_value' => null,
			'varchar_value' => null,
			'text_value' => null,
		];
		for ($i = 0; $i < self::MAX_DEPTH; $i++) {
			$insert["path$i"] = $i < $depth ? $path[$i] : null;
		}

		// get value type
		if (is_bool($value)) {
			$type = "bool";
		}
		else if (is_int($value)) {
			$type = "int";
		}
		else if (is_string($value)) {
			$type = strlen($value) < self::MAX_VARCHAR_LEN ? 'varchar' : 'text';
		}
		else {
			throw new Exception("Unknown FireDB value type.");
		}

		$insert["type"] = $type;
		$insert[$type . "_value"] = $value;

		return $insert;
	}

	private function generateObjectInsert($pathstr, $value, &$output) {
		foreach ($value as $key => $child_value) {
			if (is_array($child_value)) {
				$this->generateObjectInsert("$pathstr/$key", $child_value, $output);
			}
			else {
				$output[] = $this->generateRowInsert("$pathstr/$key", $child_value);
			}
		}
	}

	public function set($pathstr, $value) {
		$path = $this->parsePath($pathstr);
		$depth = count($path);

		$where = [];
		for ($i = 0; $i < $depth; $i++) {
			$where["path$i"] = $path[$i];
		}

		if (is_array($value)) {
			$insert = [];
			$this->generateObjectInsert($pathstr, $value, $insert);
		}
		else {
			$insert = $this->generateRowInsert($pathstr, $value);
		}

		$this->db->delete($this->table, $where);
		$this->db->insert($this->table, $insert);
	}

	public function now() {
		return (int)(microtime(true) * 1000);
	}

	public function generateKey() {
		$now = $this->now();

		// TODO: actual key generation
		$key = $now;

		return $key;
	}
}
