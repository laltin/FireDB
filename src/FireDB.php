<?php
require_once(__DIR__ . '/Medoo.php');

class FireDB {
	private const MAX_DEPTH = 10;
	private const MAX_VARCHAR_LEN = 255;

	private $db;
	private $table;

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

		$obj = [];
		$this->db->select($this->table, "*", $where, function($row) use (&$obj, $depth) {
			$child = &$obj;

			for ($i = $depth; isset($row["path$i"]); $i++) {
				$key = $row["path$i"];

				if (!isset($child[$key])) {
					$child[$key] = [];
				}

				$child = &$child[$key];
			}

			$type = $row["type"];
			$child = $row[$type . "_value"];

			if ($type == 'bool') {
				// bool is not always saved as bool in the database, convert to bool
				$child = $child ? true : false;
			}
			else if ($type == 'int') {
				// for some reason int types return as string not int, convert to int
				$child = (int)$child;
			}
		});
		return $obj;
	}

	private function generateRow($pathstr, $value) {
		$path = $this->parsePath($pathstr);
		$depth = count($path);

		$insert = [];
		for ($i = 0; $i < $depth; $i++) {
			$insert["path$i"] = $path[$i];
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
			return null;
		}

		$insert["type"] = $type;
		$insert[$type . "_value"] = $value;

		return $insert;
	}

	public function set($pathstr, $value) {
		$path = $this->parsePath($pathstr);
		$depth = count($path);

		$where = [];
		for ($i = 0; $i < $depth; $i++) {
			$where["path$i"] = $path[$i];
		}

		// get value type
		if (is_array($value)) {
			// TODO
		}
		else if ($insert = $this->generateRow($pathstr, $value)) {
			// nothing to do, just insert the object
		}
		else {
			throw new Exception("Unknown FireDB value type.");
		}

		$this->db->delete($this->table, $where);
		$this->db->insert($this->table, $insert);
	}
}
