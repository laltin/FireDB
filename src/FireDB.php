<?php
require_once(__DIR__ . '/Medoo/Medoo.php');

class FireDB {
	private const MAX_DEPTH = 10;
	private const MAX_VARCHAR_LEN = 255;

	private $db;
	private $table;

	/**
	 * $db = new FireDB([
	 *     'type' => 'mysql',
 	 *     'host' => 'localhost',
	 *     'username' => 'root',
	 *     'password' => '',
	 *     'database' => 'project-db'
	 * ], 'json');
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

	public function get($pathstr, $range = null) {
		$path = $this->parsePath($pathstr);
		$depth = count($path);

		// reconstruct object
		$obj = null;
		$reconstructor = function($row) use (&$obj, $depth) {
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
			if ($type == 'bool') {
				$child = (bool)$row["int_value"];
			}
			else if ($type == 'int') {
				// convert value to int
				$child = (int)$row["int_value"];
			}
			else {
				$child = $row[$type . "_value"];
			}
		};

		if (!isset($range)) {
			// simple query, return full object
			$where = [];
			for ($i = 0; $i < $depth; $i++) {
				$where["path$i"] = $path[$i];
			}

			$this->db->select($this->table, "*", $where, $reconstructor);
		}
		else {
			// range query, return children that match range
			if (count($range) != 1) {
				throw new Exception("FireDB range queries work only with one property");
			}

			$ids_col = "path$depth"; // the column on which ids for children are searched
			$index_on = array_key_first($range); // property on which range condition is applied
			$inputs = [];

			$where_range = [];
			if (isset($range[$index_on]['start'])) {
				$where_range[] = '<int_value> >= :start';
				$inputs['start'] = $range[$index_on]['start'];
			}
			if (isset($range[$index_on]['end'])) {
				$where_range[] = '<int_value> <= :end';
				$inputs['end'] = $range[$index_on]['end'];
			}
			if (count($where_range) == 0) {
				throw new Exception("No range given for FireDB range query");
			}
			$where_range = join(' AND ', $where_range);

			$inputs['index_hash'] = $this->getIndexHash([...$path, $ids_col, $index_on]);

			$where_path = [];
			for ($i = 0; $i < $depth; $i++) {
				$where_path[] = "<path$i> = :path$i";
				$inputs["path$i"] = $path[$i];
			}
			$where_path = join(' AND ', $where_path);

			$table = $this->table;
			$query = "SELECT <$ids_col> FROM <$table> WHERE <index_hash>=:index_hash AND $where_range";
			$query = "SELECT * FROM <$table> WHERE $where_path AND <$ids_col> IN ($query)";
			$query = $this->db->query($query, $inputs);

			while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
				$reconstructor($row);
			}
		}

		return $obj;
	}

	private function getIndexHash($path) {
		if (count($path) <= 2) {
			return null;
		}

		$index_root = join('/', array_slice($path, 0, -2));
		$index_on = end($path);
		return md5("$index_root:$index_on");
	}

	private function generateRowInsert($pathstr, $value) {
		$path = $this->parsePath($pathstr);
		$depth = count($path);

		$insert = [
			'int_value' => null,
			'varchar_value' => null,
			'text_value' => null,
			'index_hash' => null,
		];
		for ($i = 0; $i < self::MAX_DEPTH; $i++) {
			$insert["path$i"] = $i < $depth ? $path[$i] : null;
		}

		// get value type and populate columns
		if (is_bool($value)) {
			$insert["type"] = 'bool';
			$insert["int_value"] = (int)$value;

			$insert["index_hash"] = $this->getIndexHash($path);
		}
		else if (is_int($value)) {
			$insert["type"] = 'int';
			$insert["int_value"] = $value;

			$insert["index_hash"] = $this->getIndexHash($path);
		}
		else if (is_string($value)) {
			$type = strlen($value) < self::MAX_VARCHAR_LEN ? 'varchar' : 'text';

			$insert["type"] = $type;
			$insert[$type . "_value"] = $value;
		}
		else {
			throw new Exception("Unknown FireDB value type");
		}

		return $insert;
	}

	private function generateObjectInsert($pathstr, $obj, &$output) {
		foreach ($obj as $key => $value) {
			if ($value === null) {
				// null value, nothing to generate
			}
			else if (is_array($value)) {
				$this->generateObjectInsert("$pathstr/$key", $value, $output);
			}
			else {
				$output[] = $this->generateRowInsert("$pathstr/$key", $value);
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

		// generate insert commands
		if ($value === null) {
			// null value, delete but don't insert anything
		}
		else if (is_array($value)) {
			$insert = [];
			$this->generateObjectInsert($pathstr, $value, $insert);
		}
		else {
			$insert = $this->generateRowInsert($pathstr, $value);
		}

		$this->db->delete($this->table, $where);
		if (isset($insert)) {
			$this->db->insert($this->table, $insert);
		}
	}

	public function now() {
		return (int)(microtime(true) * 1000);
	}

	private const KEY_CHARS = '-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz';

	public function generateKey() {
		static $lastTime = null;
		static $randBased = null;

		$now = $this->now();
		$duplicateTime = ($now == $lastTime);
		$lastTime = $now;

		$timeBased = [];
		for ($i = 0; $i < 8; $i++) {
			$timeBased[] = self::KEY_CHARS[ $now % 64 ];
			$now = (int)($now / 64);
		}
		// reverse, so that string sorted keys are also ordered in time
		$timeBased = array_reverse($timeBased);

		if (!$duplicateTime) {
			$randBased = [];
			for ($i=0; $i < 12; $i++) {
				$randBased[] = rand(0, 63);
			}
		}
		else {
			// time hasn't changed since last key generation (ms resolution)
			// use same random number but increment by one. this ensures that
			// multiple calls to generateKey return sorted keys
			for ($i = 11; $i >= 0 && $randBased[$i] == 63; $i--) {
		        $randBased[$i] = 0;
	      	}
			$randBased[$i] += 1;
		}

		$key = join('', $timeBased);
		for ($i = 0; $i < 12; $i++) {
			$key = $key . self::KEY_CHARS[ $randBased[$i] ];
		}

		return $key;
	}
}
