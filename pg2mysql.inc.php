<?php
/*
This file is part of the 'Pg2MySQL' converter project
http://www.lightbox.org/pg2mysql.php

Copyright (C) 2005-2011 James Grant <james@lightbox.org>
            Lightbox Technologies Inc.

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public
License as published by the Free Software Foundation, version 2.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; see the file COPYING.  If not, write to
the Free Software Foundation, Inc., 59 Temple Place - Suite 330,
Boston, MA 02111-1307, USA.
*/

ini_set("memory_limit", "512M");
error_reporting(E_ALL & ~E_DEPRECATED);
define('PRODUCT', "pg2mysql");
define('VERSION', "1.9");
define('COPYRIGHT', "Lightbox Technologies Inc. http://www.lightbox.ca");

//this is the default, it can be overridden here, or specified as the third parameter on the command line
$config['engine']="InnoDB";
$config['autoincrement_key_type'] = getenv("PG2MYSQL_AUTOINCREMENT_KEY_TYPE") ? getenv("PG2MYSQL_AUTOINCREMENT_KEY_TYPE") : "PRIMARY KEY";
$config['domains'] = array();
$config['domainschema'] = null;
$config['verbose'] = false;
$config['sqlite'] = false;
$config['no-header'] = false;

// Timezone to use
date_default_timezone_set('UTC');

function write_debug($message) {
    global $config;
    if ($config['verbose']) {
        fwrite(STDERR, "$message\n");
    }
}

function getfieldname($l)
{
    //first check if its in nice quotes for us
    if (preg_match("/`(.*)`/",$l,$regs)) {
        if ($regs[1]) {
            return $regs[1];
        } else {
            return null;
        }
    }
    //if its not in quotes, then it should (we hope!) be the first "word" on the line, up to the first space.
    else if (preg_match("/([^\ ]*)/", trim($l), $regs)) {
        if ($regs[1]) {
            return $regs[1];
        } else {
            return null;
        }
    }

	return null;
}

function formatsize($s)
{
    if ($s < pow(2, 14)) {
        return "{$s}B";
    } else if ($s < pow(2, 20)) {
        return sprintf("%.1f", round($s/1024, 1))."K";
    } else if ($s < pow(2, 30)) {
        return sprintf("%.1f", round($s/1024/1024, 1))."M";
    } else {
        return sprintf("%.1f", round($s/1024/1024/1024, 1))."G";
    }
}

function pg2mysql_large($infilename, $outfilename, $httpDownload = false) {
    global $config;

	$from_stdin = 0;
	$fs = null;
	if ($infilename == '-') {
		$infilename = 'php://STDIN';
		$from_stdin = 1;
	} else {
		$fs=filesize($infilename);
	}
	$infp=fopen($infilename,"rt");
	if ($outfilename == '-') {
		$outfilename = 'php://STDOUT';
	}
	$outfp=fopen($outfilename,"wt");\

    //we read until we get a semicolon followed by a newline (;\n);
    $pgsqlchunk=array();
    $chunkcount=1;
    $linenum=0;
    $inquotes=false;
    $first = !$config['no-header'];
	if (!$httpDownload && !$from_stdin) {
		fprintf(STDERR, "Filesize: ".formatsize($fs)."\n");
	}

    while ($instr = fgets($infp)) {
        $linenum++;
        $memusage = round(memory_get_usage(true) / 1024 / 1024);
        $len = strlen($instr);
        $pgsqlchunk[] = $instr;
        $c = substr_count($instr, "'");

        //we have an odd number of ' marks
        if ($c % 2 != 0) {
            if ($inquotes) {
                $inquotes = false;
            } else {
                $inquotes = true;
            }

            $currentpos=ftell($infp);
            $progress=$currentpos/$fs;

            if ($progress == 1.0 || (strlen($instr)>3 && ($instr[$len-3]==")" && $instr[$len-2]==";" && $instr[$len-1]=="\n") && $inquotes==false)) {
                $chunkcount++;
            }

            if (!$httpDownload && !$from_stdin && $linenum % 10000 == 0) {
                $percent = round($currentpos / $fs * 100);
                $position = formatsize($currentpos);

                fprintf(STDERR, "Reading  progress: %3d%%   position: %7s   line: %9d   sql chunk: %9d  mem usage: %4dM\r",$percent,$position,$linenum,$chunkcount,$memusage);
            }


            $mysqlchunk=pg2mysql($pgsqlchunk, $first);
            fputs($outfp, $mysqlchunk);

			$first=false;
			$pgsqlchunk = array();
			$mysqlchunk = "";
		}
	}

    if ($httpDownload) {
        ob_clean();

        header("Content-type: application/octet-stream");
        header("Content-Disposition: attachment; filename=$outfilename");
        echo file_get_contents($outfilename);
        @unlink($outfilename);
        exit;
    } else {
        fwrite(STDERR, "\n\n");
        fprintf(STDERR, "Completed! %9d lines   %9d sql chunks\n\n",$linenum,$chunkcount);
    }

    fclose($infp);
    fclose($outfp);
}

function pg2mysql($input, $header = true) {
    global $config;

    if (is_array($input)) {
        $lines=$input;
    } else {
        $lines = explode("\n", $input);
    }

    if ($header) {
        $output = "# Converted with ".PRODUCT."-".VERSION."\n";
        $output.= "# Converted on ".date("r")."\n";
        $output.= "# ".COPYRIGHT."\n\n";
        $output.="SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\nSET time_zone=\"+00:00\";\n\n";
    } else {
        $output="";
    }

    $in_create_table = $in_insert = false;

    $linenumber=0;
    $tbl_extra="";
    $values = array();
    $heads = null;
    while (isset($lines[$linenumber])) {
        $line=$lines[$linenumber];
        if (substr($line, 0, 12) == "CREATE TABLE") {
            $in_create_table = true;
            $line = str_replace("\"", "`", $line);
            $output .= $line;
            $linenumber++;
            continue;
        }

		if (!$config['domainschema'] && preg_match('/SET\s+search_path\s*=\s*([^,\s]+)/', $line, $matches)) {
			$config['domainschema'] = $matches[1];
			write_debug("Schema: " . $config['domainschema']);
		}

		if (substr($line,0,12)=="CREATE TABLE") {
			$in_create_table=true;
			$line=str_replace("\"","`",$line);
			$output.=$line;
			$linenumber++;
			continue;
		}

		if (substr($line,0,2)==");" && $in_create_table) {
			$in_create_table=false;
			$line=")" . ($config['sqlite'] ? "" : " ENGINE={$config['engine']}") . ";\n\n";

            $output.=$tbl_extra;
            $output.=$line;

            $linenumber++;
            $tbl_extra="";
            continue;
        }

        if ($in_create_table) {
            /*
                Replace domains with their PostgreSQL definitions
            */
            foreach ($config['domains'] AS $dom => $def) {
                if ($config['domainschema']) {
                    $line = preg_replace('/\b' . $config['domainschema'] . '.' . $dom . '\b/', $def, $line);
                }

                $line = preg_replace('/\b' . $dom . '\b/', $def, $line);
            }

            $line=str_replace("\"", "`", $line);
            $line=str_replace(" integer", " int(11)", $line);
            $line=str_replace(" int_unsigned", " int(11) UNSIGNED", $line);
            $line=str_replace(" smallint_unsigned", " smallint UNSIGNED", $line);
            $line=str_replace(" bigint_unsigned", " bigint UNSIGNED", $line);
            $line=str_replace(" serial ", " int(11) auto_increment ", $line);
            $line=str_replace(" bytea", " BLOB", $line);
            $line=str_replace(" boolean", " bool", $line);
            $line=str_replace(" bool DEFAULT true", " bool DEFAULT 1", $line);
            $line=str_replace(" bool DEFAULT false", " bool DEFAULT 0", $line);
            $line=str_replace("` `text`", "` text", $line); // fix because pg_dump quotes text type for some reason
            if (preg_match("/ character varying\(([0-9]*)\)/", $line, $regs)) {
                $num=$regs[1];
                if ($num<=255) {
                    $line=preg_replace("/ character varying\([0-9]*\)/", " varchar($num)", $line);
                } else {
                    $line=preg_replace("/ character varying\([0-9]*\)/", " text", $line);
                }
            }

            //character varying with no size, we will default to varchar(255)
            if (preg_match("/ character varying/", $line)) {
                $line=preg_replace("/ character varying/", " varchar(255)", $line);
            }

			$line=str_replace("\"","`",$line);
			$line=str_replace(" integer"," int(11)",$line);
			$line=str_replace(" int_unsigned"," int(11) UNSIGNED",$line);
			$line=str_replace(" smallint_unsigned"," smallint UNSIGNED",$line);
			$line=str_replace(" bigint_unsigned"," bigint UNSIGNED",$line);
			$line=str_replace(" serial "," int(11) auto_increment ",$line);
			$line=str_replace(" bytea"," BLOB",$line);
			$line=str_replace(" boolean"," bool",$line);
			$line=str_replace(" bool DEFAULT true"," bool DEFAULT 1",$line);
			$line=str_replace(" bool DEFAULT false"," bool DEFAULT 0",$line);
			if (preg_match("/ character varying\(([0-9]*)\)/",$line,$regs)) {
				$num=$regs[1];
				if ($num<=255)
					$line=preg_replace("/ character varying\([0-9]*\)/"," varchar($num)",$line);
				else
					$line=preg_replace("/ character varying\([0-9]*\)/"," text",$line);
			}
			//character varying with no size, we will default to varchar(255)
			if (preg_match("/ character varying/",$line)) {
				$line=preg_replace("/ character varying/"," varchar(255)",$line);
			}

            if (preg_match("/DEFAULT \('([0-9]*)'::int/", $line, $regs) ||
                preg_match("/DEFAULT \('([0-9]*)'::smallint/", $line, $regs) ||
                preg_match("/DEFAULT \('([0-9]*)'::bigint/", $line, $regs)
                        ) {
                $num=$regs[1];
                $line=preg_replace("/ DEFAULT \('([0-9]*)'[^ ,]*/", " DEFAULT $num ", $line);
            }
            if (preg_match("/DEFAULT \(([0-9\-]*)\)/", $line, $regs)) {
                $num=$regs[1];
                $line=preg_replace("/ DEFAULT \(([0-9\-]*)\)/", " DEFAULT $num ", $line);
            }
            $line=preg_replace("/ DEFAULT nextval\(.*\) /", " auto_increment ", $line);
            $line=preg_replace("/::.*,/", ",", $line);
            $line=preg_replace("/::.*$/", "\n", $line);
            if (preg_match("/character\(([0-9]*)\)/", $line, $regs)) {
                $num=$regs[1];
                if ($num<=255) {
                    $line=preg_replace("/ character\([0-9]*\)/", " varchar($num)", $line);
                } else {
                    $line=preg_replace("/ character\([0-9]*\)/", " text", $line);
                }
            }
            //timestamps
            $line=str_replace(" timestamp with time zone", " timestamp", $line);
            $line=str_replace(" timestamp without time zone", " timestamp", $line);
            $line = str_replace(" timestamp(0) without time zone", " timestamp", $line);
            $line = str_replace(" timestamp DEFAULT NULL", " DATETIME DEFAULT NULL", $line);
            $line = str_replace(" timestamp NOT NULL", " DATETIME DEFAULT CURRENT_TIMESTAMP", $line);
            $line=preg_replace("/ date DEFAULT '(.*)(\+|\-).*'/", ' date DEFAULT \'${1}\'', $line);

            //time
            $line=str_replace(" time with time zone", " time", $line);
            $line=str_replace(" time without time zone", " time", $line);

            $line=str_replace(" timestamp DEFAULT now()", " timestamp DEFAULT CURRENT_TIMESTAMP", $line);
            $line=preg_replace("/ timestamp( NOT NULL)?(,|$)/", ' timestamp DEFAULT 0${1}${2}', $line);
            $line = str_replace(" timestamp DEFAULT now()", " timestamp DEFAULT CURRENT_TIMESTAMP", $line);

            // Remove defaults pointing to functions
            $line=preg_replace("/ DEFAULT .*\(\)/", "", $line);

            if (strstr($line, "auto_increment")) {
                $field=getfieldname($line);
                $tbl_extra.=", " . $config['autoincrement_key_type'] . "(`$field`)\n";
            }

            $specialfields=array("repeat","status","type","call", "key", "regexp");

            $field=getfieldname($line);
            if (in_array($field, $specialfields)) {
                $line=str_replace("$field ", "`$field` ", $line);
            }

            //text/blob fields are not allowed to have a default, so if we find a text DEFAULT, change it to varchar(255) DEFAULT
            if (strstr($line, "text DEFAULT")) {
                $line=str_replace(" text DEFAULT ", " varchar(255) DEFAULT ", $line);
            }

            //just skip a CONSTRAINT line
            if (strstr($line, " CONSTRAINT ")) {
                $line="";
                //and if the previous output ended with a , remove the ,
                $lastchr=substr($output, -2, 1);
                //	echo "lastchr=$lastchr";
                if ($lastchr==",") {
                    $output=substr($output, 0, -2)."\n";
                }
            }

            $output.=$line;
        }

        if (substr($line, 0, 11)=="INSERT INTO") {
            //this insert spans multiple lines, so keep dumping the lines until we reach a line
            //that ends with  ");"

            list($before, $after)=explode("VALUES", $line, 2);
            //we only replace the " with ` in what comes BEFORE the VALUES
            //(ie, field names, like INSERT INTO table ("bla","bla2") VALUES ('s:4:"test"','bladata2');
            //should convert to      INSERT INTO table (`bla`,`bla2`) VALUES ('s:4:"test"','bladata2');

            $before=str_replace("\"", "`", $before);

            //in after, we need to watch out for escape format strings, ie (E'escaped \r in a string'), and ('bla',E'escaped \r in a string')
            //ugh i guess its possible these strings could exist IN the data as well, but the only way to solve that is to process these lines one character
            //at a time, and thats just stupid, so lets just hope this doesnt appear anywhere in the actual data
            //also there is a situation where string ends with \ (backslash). For example, 'C:\' and it's valid for pg, but not for mysql.
            //the regexp looks odd, the preblem is that in PHP regexps we have to use 4 (four!) backslashes to represend one real!
            //here is the regexp without escaping: (([^\]|^)(\\)*\)'
            $after=preg_replace(array("/(, | \()E'/", "/(([^\\\\]|^)(\\\\\\\\)*\\\\)'/"), array('\1\'', '\1\\\''), $after);

            $c=substr_count($line, "'");
            //we have an odd number of ' marks
            if ($c%2!=0) {
                $inquotes=true;
            } else {
                $inquotes=false;
            }

            $output.=$before."VALUES".$after;
            while (substr($lines[$linenumber], -3, -1)!=");" || $inquotes) {
                $linenumber++;
                $line=$lines[$linenumber];

                //in after, we need to watch out for escape format strings, ie (E'escaped \r in a string'), and ('bla',E'escaped \r in a string')
                //ugh i guess its possible these strings could exist IN the data as well, but the only way to solve that is to process these lines one character
                //at a time, and thats just stupid, so lets just hope this doesnt appear anywhere in the actual data
                //also there is a situation where string ends with \ (backslash). For example, 'C:\' and it's valid for pg, but not for mysql.
                //the regexp looks odd, the preblem is that in PHP regexps we have to use 4 (four!) backslashes to represend one real!
                //here is the regexp without escaping: (([^\]|^)(\\)*\)'
                $line=preg_replace(array("/, E'/", "/(([^\\\\]|^)(\\\\\\\\)*\\\\)'/"), array(", '", '\1\\\''), $line);
                $output.=$line;

                //					printf("inquotes: %d linenumber: %4d line: %s\n",$inquotes,$linenumber,$lines[$linenumber]);

                $c=substr_count($line, "'");
                //we have an odd number of ' marks
                if ($c%2!=0) {
                    if ($inquotes) {
                        $inquotes=false;
                    } else {
                        $inquotes=true;
                    }
                    //						echo "inquotes=$inquotes\n";
                }
            }
        }
        if (substr($line, 0, 16)=="ALTER TABLE ONLY") {
            $line=preg_replace('/ ONLY/', '', $line);
            $line=str_replace("\"", "`", $line);
            $pkey=$line;

            $linenumber++;
            if (isset($lines[$linenumber])) {
                $line=$lines[$linenumber];

                if (preg_match('/(PRIMARY KEY|UNIQUE).*\);\s*$/', $line)) {
                    //looks like we have a single line PRIMARY KEY or UNIQUE definition, lets go ahead and add it
                    $output.=$pkey;
                    //MySQL and Postgres syntax are similar here, minus quoting differences
                    $output.=str_replace("\"", "`", $line);
                }

                if ($config['engine'] == "InnoDB") {
                    if (substr($line, 0, 18) == "ADD CONSTRAINT fk_") {
                        $output .= $pkey;
                        $output .= $line . "\n";
                    }
                }
            }
		}

        //while we're here, we might as well catch CREATE INDEX as well
        if (substr($line, 0, 12)=="CREATE INDEX") {
            preg_match('/CREATE INDEX "?([a-zA-Z0-9_]*)"? ON "?([a-zA-Z0-9_]*)"? USING btree \((.*)\);/', $line, $matches);
            if (isset($matches[1]) && isset($matches[2]) && isset($matches[3])) {
                $indexname=$matches[1];
                $tablename=$matches[2];
                $columns=str_replace("\"", "`", $matches[3]);
                $output.="ALTER TABLE `{$tablename}` ADD INDEX ( {$columns} ) ;\n";
            }
        }

        if (substr($line, 0, 13) == 'DROP DATABASE') {
            preg_match('/DROP DATABASE "([a-zA-Z0-9_]*)"/', $line, $matches);
            $output .= "DROP DATABASE IF EXISTS `$matches[1]`;\n\n";
        }

        if (substr($line, 0, 15) == 'CREATE DATABASE') {
            preg_match('/CREATE DATABASE "([a-zA-Z0-9_]*)" .* ENCODING = \'(.*?)\'/', $line, $matches);
            $output .= "CREATE DATABASE `$matches[1]` DEFAULT CHARACTER SET $matches[2];\n\n";
            $dbname = $matches[1];
        }

        if (preg_match('/^\s*CREATE DOMAIN/', $line)) {
            $def = $line;
            while (!preg_match('/;\s*$/', $line) && isset($lines[$linenumber+1])) {
                $linenumber++;
                $line = $lines[$linenumber];
                $def .= $line;
            }

            if (preg_match('/CREATE DOMAIN\s+([a-zA-Z0-9_\.]+)\s+AS\s+(.+?)(\s*;|\s+CONSTRAINT)/', $def, $matches)) {
                $config['domains'][$matches[1]] = str_replace('NOT NULL', '', $matches[2]);
            }
        }

         if (substr($line, 0, 8) == '\\connect') {
            preg_match('/connect "([a-zA-Z0-9_]*)"/', $line, $matches);
            $output .= "USE `$matches[1]`;\n\n";
        }

        if (substr($line, 0, 5) == 'COPY ') {
            preg_match('/COPY (.*) FROM stdin/', $line, $matches);
            $heads = str_replace('"', "`", $matches[1]);
            $values = array();
            $in_insert = true;
        } else if ($in_insert) {
            if ($line == "\\.\n") {
                $in_insert = false;
                if ($values) {
                    $output .= "INSERT INTO $heads VALUES\n" . implode(",\n", $values) . ";\n\n";
                }
            } else {
                $line = str_replace('\N', 'NULL', $line);
                $vals = explode('	', $line);
                foreach ($vals as $i => $val) {
                    $val = trim($val);
                    switch ($val) {
                        case '\\N':
                            $vals[$i] = 'NULL';
                            break;
                        case 't':
                            $vals[$i] = 'true';
                            break;
                        case 'f':
                            $vals[$i] = 'false';
                            break;
                        default:
                            $vals[$i] = "'" . str_replace("'", "\\'", trim($val)) . "'";
                    }
                }

                $values[] = '(' . implode(',', $vals) . ')';
                if (count($values) >= 1000) {
                    $output .= "INSERT INTO $heads VALUES\n" . implode(",\n", $values) . ";\n";
                    $values = array();
                }
            }
        }

        $linenumber++;
    }
    return str_replace('`public`.','',$output);
}

function read_domains($input)
{
	global $config;

	if (is_array($input)) {
		$lines=$input;
	} else {
		$lines=split("\n",$input);
	}

	while(count($lines)) {
		$line = array_shift($lines);

		if (preg_match('/SET\s+search_path\s*=\s*([^,\s]+)/', $line, $matches)) {
			$config['domainschema'] = $matches[1];
			write_debug("Schema: " . $config['domainschema']);
		}

		if (preg_match('/^\s*CREATE DOMAIN/', $line)) {
			$def = $line;
			while (!preg_match('/;\s*$/', $def) && count($lines)) {
				$def .= array_shift($lines);
			}

			if (preg_match('/CREATE DOMAIN\s+([a-zA-Z0-9_\.]+)\s+AS\s+(.+?)(\s*;|\s+CONSTRAINT)/', $def, $matches)) {
				// take NOT NULL out of domain def since it also appears on the column definition
				$config['domains'][$matches[1]] = str_replace('NOT NULL', '', $matches[2]);
			}
		}
	}
}
