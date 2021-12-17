CREATE TABLE `json` (
  `path0` varchar(32) NOT NULL,
  `path1` varchar(32) DEFAULT NULL,
  `path2` varchar(32) DEFAULT NULL,
  `path3` varchar(32) DEFAULT NULL,
  `path4` varchar(32) DEFAULT NULL,
  `path5` varchar(32) DEFAULT NULL,
  `path6` varchar(32) DEFAULT NULL,
  `path7` varchar(32) DEFAULT NULL,
  `path8` varchar(32) DEFAULT NULL,
  `path9` varchar(32) DEFAULT NULL,
  `type` varchar(15) NOT NULL,
  `bool_value` tinyint(1) DEFAULT NULL,
  `int_value` int(11) DEFAULT NULL,
  `varchar_value` varchar(255) DEFAULT NULL,
  `text_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- indexes
ALTER TABLE `json`
  ADD UNIQUE KEY `path` (`path0`,`path1`,`path2`,`path3`,`path4`,`path5`,`path6`,`path7`,`path8`,`path9`) USING BTREE;
COMMIT;
