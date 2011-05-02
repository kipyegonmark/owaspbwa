<?php
/*
 * $RCSfile: adodb-db2.inc.php,v $
 *
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2006 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
/* 
  This is a version of the ADODB driver for DB2.  It uses the 'ibm_db2' PECL extension for PHP
  (http://pecl.php.net/package/ibm_db2), which in turn requires DB2 V8.2.

  Tested with PHP 5.1.1 and Apache 2.0.55 on Windows XP SP2.

  This file was ported from "adodb-odbc.inc.php" by Larry Menard, "larry.menard@rogers.com".
  I ripped out what I believed to be a lot of redundant or obsolete code, but there are
  probably still some remnants of the ODBC support in this file; I'm relying on reviewers
  of this code to point out any other things that can be removed.

  Reviewers should include:

    - Dan Scott (owner of 'ibm_db2' PECL extension)
    - John Lim  (owner of ADOdb)
*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

  define("_ADODB_DB2_LAYER", 2 );
	 
/*--------------------------------------------------------------------------------------
--------------------------------------------------------------------------------------*/


class ADODB_db2 extends ADOConnection {
	var $databaseType = "db2";	
	var $fmtDate = "'Y-m-d'";
	var $fmtTimeStamp = "'Y-m-d, h:i:sA'";
	var $replaceQuote = "''"; // string to use to replace quotes
	var $dataProvider = "db2";
	var $hasAffectedRows = true;

	var $binmode = DB2_BINARY;

	var $useFetchArray = false; // setting this to true will make array elements in FETCH_ASSOC mode case-sensitive
								// breaking backward-compat
	var $_bindInputArray = false;	
	var $_genSeqSQL = "create table %s (id integer)";
	var $_autocommit = true;
	var $_haserrorfunctions = true;
	var $_lastAffectedRows = 0;
	var $uCaseTables = true; // for meta* functions, uppercase table names
	
	function ADODB_db2() 
	{ 	
		$this->_haserrorfunctions = ADODB_PHPVER >= 0x4050;
	}
	
		// returns true or false
	function _connect($argDSN, $argUsername, $argPassword, $argDatabasename)
	{
		global $php_errormsg;
		
		if (!function_exists('db2_connect')) return null;
		
		// This needs to be set before the connect().
		// Replaces the odbc_binmode() call that was in Execute()
		ini_set('ibm_db2.binmode', $this->binmode);

		if ($argDatabasename) {
			$this->_connectionID = db2_connect($argDatabasename,$argUsername,$argPassword);
		} else {
			$this->_connectionID = db2_connect($argDSN,$argUsername,$argPassword);
		}
		if (isset($php_errormsg)) $php_errormsg = '';

		// For db2_connect(), there is an optional 4th arg.  If present, it must be
		// an array of valid options.  So far, we don't use them.

		$this->_errorMsg = isset($php_errormsg) ? $php_errormsg : '';
		if (isset($this->connectStmt)) $this->Execute($this->connectStmt);
		
		return $this->_connectionID != false;
	}
	
	// returns true or false
	function _pconnect($argDSN, $argUsername, $argPassword, $argDatabasename)
	{
		global $php_errormsg;
	
		if (!function_exists('db2_connect')) return null;
		
		// This needs to be set before the connect().
		// Replaces the odbc_binmode() call that was in Execute()
		ini_set('ibm_db2.binmode', $this->binmode);

		if (isset($php_errormsg)) $php_errormsg = '';
		$this->_errorMsg = isset($php_errormsg) ? $php_errormsg : '';
		
		if ($argDatabasename) {
			$this->_connectionID = db2_pconnect($argDatabasename,$argUsername,$argPassword);
		} else {
			$this->_connectionID = db2_pconnect($argDSN,$argUsername,$argPassword);
		}
		if (isset($php_errormsg)) $php_errormsg = '';

		$this->_errorMsg = isset($php_errormsg) ? $php_errormsg : '';
		if ($this->_connectionID && $this->autoRollback) @db2_rollback($this->_connectionID);
		if (isset($this->connectStmt)) $this->Execute($this->connectStmt);
		
		return $this->_connectionID != false;
	}

	
	function ServerInfo()
	{
	
		if (!empty($this->host) && ADODB_PHPVER >= 0x4300) {
			$dsn = strtoupper($this->host);
			$first = true;
			$found = false;
			
			if (!function_exists('db2_data_source')) return false;
			
			while(true) {
				
				$rez = @db2_data_source($this->_connectionID,
					$first ? SQL_FETCH_FIRST : SQL_FETCH_NEXT);
				$first = false;
				if (!is_array($rez)) break;
				if (strtoupper($rez['server']) == $dsn) {
					$found = true;
					break;
				}
			} 
			if (!$found) return ADOConnection::ServerInfo();
			if (!isset($rez['version'])) $rez['version'] = '';
			return $rez;
		} else {
			return ADOConnection::ServerInfo();
		}
	}

	
	function CreateSequence($seqname='adodbseq',$start=1)
	{
		if (empty($this->_genSeqSQL)) return false;
		$ok = $this->Execute(sprintf($this->_genSeqSQL,$seqname));
		if (!$ok) return false;
		$start -= 1;
		return $this->Execute("insert into $seqname values($start)");
	}
	
	var $_dropSeqSQL = 'drop table %s';
	function DropSequence($seqname)
	{
		if (empty($this->_dropSeqSQL)) return false;
		return $this->Execute(sprintf($this->_dropSeqSQL,$seqname));
	}
	
	/*
		This algorithm is not very efficient, but works even if table locking
		is not available.
		
		Will return false if unable to generate an ID after $MAXLOOPS attempts.
	*/
	function GenID($seq='adodbseq',$start=1)
	{	
		// if you have to modify the parameter below, your database is overloaded,
		// or you need to implement generation of id's yourself!
		$MAXLOOPS = 100;
		while (--$MAXLOOPS>=0) {
			$num = $this->GetOne("select id from $seq");
			if ($num === false) {
				$this->Execute(sprintf($this->_genSeqSQL ,$seq));	
				$start -= 1;
				$num = '0';
				$ok = $this->Execute("insert into $seq values($start)");
				if (!$ok) return false;
			} 
			$this->Execute("update $seq set id=id+1 where id=$num");
			
			if ($this->affected_rows() > 0) {
				$num += 1;
				$this->genID = $num;
				return $num;
			}
		}
		if ($fn = $this->raiseErrorFn) {
			$fn($this->databaseType,'GENID',-32000,"Unable to generate unique id after $MAXLOOPS attempts",$seq,$num);
		}
		return false;
	}


	function ErrorMsg()
	{
		if ($this->_haserrorfunctions) {
			if ($this->_errorMsg !== false) return $this->_errorMsg;
			if (empty($this->_connectionID)) return @db2_errormsg();
			return @db2_errormsg($this->_connectionID);
		} else return ADOConnection::ErrorMsg();
	}
	
	function ErrorNo()
	{
		
		if ($this->_haserrorfunctions) {
			if ($this->_errorCode !== false) {
				// bug in 4.0.6, error number can be corrupted string (should be 6 digits)
				return (strlen($this->_errorCode)<=2) ? 0 : $this->_errorCode;
			}

			if (empty($this->_connectionID)) $e = @db2_error(); 
			else $e = @db2_error($this->_connectionID);
			
			 // bug in 4.0.6, error number can be corrupted string (should be 6 digits)
			 // so we check and patch
			if (strlen($e)<=2) return 0;
			return $e;
		} else return ADOConnection::ErrorNo();
	}
	
	

	function BeginTrans()
	{	
		if (!$this->hasTransactions) return false;
		if ($this->transOff) return true; 
		$this->transCnt += 1;
		$this->_autocommit = false;
		return db2_autocommit($this->_connectionID,false);
	}
	
	function CommitTrans($ok=true) 
	{ 
		if ($this->transOff) return true; 
		if (!$ok) return $this->RollbackTrans();
		if ($this->transCnt) $this->transCnt -= 1;
		$this->_autocommit = true;
		$ret = db2_commit($this->_connectionID);
		db2_autocommit($this->_connectionID,true);
		return $ret;
	}
	
	function RollbackTrans()
	{
		if ($this->transOff) return true; 
		if ($this->transCnt) $this->transCnt -= 1;
		$this->_autocommit = true;
		$ret = db2_rollback($this->_connectionID);
		db2_autocommit($this->_connectionID,true);
		return $ret;
	}
	
	function MetaPrimaryKeys($table)
	{
	global $ADODB_FETCH_MODE;
	
		if ($this->uCaseTables) $table = strtoupper($table);
		$schema = '';
		$this->_findschema($table,$schema);

		$savem = $ADODB_FETCH_MODE;
		$ADODB_FETCH_MODE = ADODB_FETCH_NUM;
		$qid = @db2_primarykeys($this->_connectionID,'',$schema,$table);
		
		if (!$qid) {
			$ADODB_FETCH_MODE = $savem;
			return false;
		}
		$rs = new ADORecordSet_db2($qid);
		$ADODB_FETCH_MODE = $savem;
		
		if (!$rs) return false;
		
		$arr =& $rs->GetArray();
		$rs->Close();
		$arr2 = array();
		for ($i=0; $i < sizeof($arr); $i++) {
			if ($arr[$i][3]) $arr2[] = $arr[$i][3];
		}
		return $arr2;
	}
	
	
	
	function &MetaTables($ttype=false)
	{
	global $ADODB_FETCH_MODE;
	
		$savem = $ADODB_FETCH_MODE;
		$ADODB_FETCH_MODE = ADODB_FETCH_NUM;
		$qid = db2_tables($this->_connectionID);
		
		$rs = new ADORecordSet_db2($qid);
		
		$ADODB_FETCH_MODE = $savem;
		if (!$rs) {
			$false = false;
			return $false;
		}
		
		$arr =& $rs->GetArray();
		
		$rs->Close();
		$arr2 = array();
		
		if ($ttype) {
			$isview = strncmp($ttype,'V',1) === 0;
		}
		for ($i=0; $i < sizeof($arr); $i++) {
			if (!$arr[$i][2]) continue;
			$type = $arr[$i][3];
			if ($ttype) { 
				if ($isview) {
					if (strncmp($type,'V',1) === 0) $arr2[] = $arr[$i][2];
				} else if (strncmp($type,'SYS',3) !== 0) $arr2[] = $arr[$i][2];
			} else if (strncmp($type,'SYS',3) !== 0) $arr2[] = $arr[$i][2];
		}
		return $arr2;
	}
	
/*
See http://msdn.microsoft.com/library/default.asp?url=/library/en-us/db2/htm/db2datetime_data_type_changes.asp
/ SQL data type codes /
#define	SQL_UNKNOWN_TYPE	0
#define SQL_CHAR			1
#define SQL_NUMERIC		 2
#define SQL_DECIMAL		 3
#define SQL_INTEGER		 4
#define SQL_SMALLINT		5
#define SQL_FLOAT		   6
#define SQL_REAL			7
#define SQL_DOUBLE		  8
#if (DB2VER >= 0x0300)
#define SQL_DATETIME		9
#endif
#define SQL_VARCHAR		12


/ One-parameter shortcuts for date/time data types /
#if (DB2VER >= 0x0300)
#define SQL_TYPE_DATE	  91
#define SQL_TYPE_TIME	  92
#define SQL_TYPE_TIMESTAMP 93

#define SQL_UNICODE                             (-95)
#define SQL_UNICODE_VARCHAR                     (-96)
#define SQL_UNICODE_LONGVARCHAR                 (-97)
*/
	function DB2Types($t)
	{
		switch ((integer)$t) {
		case 1:	
		case 12:
		case 0:
		case -95:
		case -96:
			return 'C';
		case -97:
		case -1: //text
			return 'X';
		case -4: //image
			return 'B';
				
		case 9:	
		case 91:
			return 'D';
		
		case 10:
		case 11:
		case 92:
		case 93:
			return 'T';
			
		case 4:
		case 5:
		case -6:
			return 'I';
			
		case -11: // uniqidentifier
			return 'R';
		case -7: //bit
			return 'L';
		
		default:
			return 'N';
		}
	}
	
	function &MetaColumns($table)
	{
	global $ADODB_FETCH_MODE;
	
		$false = false;
		if ($this->uCaseTables) $table = strtoupper($table);
		$schema = '';
		$this->_findschema($table,$schema);
		
		$savem = $ADODB_FETCH_MODE;
		$ADODB_FETCH_MODE = ADODB_FETCH_NUM;
	
        	$colname = "%";
	        $qid = db2_columns($this->_connectionID, "", $schema, $table, $colname);
		if (empty($qid)) return $false;
		
		$rs =& new ADORecordSet_db2($qid);
		$ADODB_FETCH_MODE = $savem;
		
		if (!$rs) return $false;
		$rs->_fetch();
		
		$retarr = array();
		
		/*
		$rs->fields indices
		0 TABLE_QUALIFIER
		1 TABLE_SCHEM
		2 TABLE_NAME
		3 COLUMN_NAME
		4 DATA_TYPE
		5 TYPE_NAME
		6 PRECISION
		7 LENGTH
		8 SCALE
		9 RADIX
		10 NULLABLE
		11 REMARKS
		*/
		while (!$rs->EOF) {
			if (strtoupper(trim($rs->fields[2])) == $table && (!$schema || strtoupper($rs->fields[1]) == $schema)) {
				$fld = new ADOFieldObject();
				$fld->name = $rs->fields[3];
				$fld->type = $this->DB2Types($rs->fields[4]);
				
				// ref: http://msdn.microsoft.com/library/default.asp?url=/archive/en-us/dnaraccgen/html/msdn_odk.asp
				// access uses precision to store length for char/varchar
				if ($fld->type == 'C' or $fld->type == 'X') {
					if ($rs->fields[4] <= -95) // UNICODE
						$fld->max_length = $rs->fields[7]/2;
					else
						$fld->max_length = $rs->fields[7];
				} else 
					$fld->max_length = $rs->fields[7];
				$fld->not_null = !empty($rs->fields[10]);
				$fld->scale = $rs->fields[8];
				$retarr[strtoupper($fld->name)] = $fld;	
			} else if (sizeof($retarr)>0)
				break;
			$rs->MoveNext();
		}
		$rs->Close(); //-- crashes 4.03pl1 -- why?
		
		if (empty($retarr)) $retarr = false;
		return $retarr;
	}
	
	function Prepare($sql)
	{
		if (! $this->_bindInputArray) return $sql; // no binding
		$stmt = db2_prepare($this->_connectionID,$sql);
		if (!$stmt) {
			// we don't know whether db2 driver is parsing prepared stmts, so just return sql
			return $sql;
		}
		return array($sql,$stmt,false);
	}

	/* returns queryID or false */
	function _query($sql,$inputarr=false) 
	{
	GLOBAL $php_errormsg;
		if (isset($php_errormsg)) $php_errormsg = '';
		$this->_error = '';
		
		if ($inputarr) {
			if (is_array($sql)) {
				$stmtid = $sql[1];
			} else {
				$stmtid = db2_prepare($this->_connectionID,$sql);
	
				if ($stmtid == false) {
					$this->_errorMsg = isset($php_errormsg) ? $php_errormsg : '';
					return false;
				}
			}
			
			if (! db2_execute($stmtid,$inputarr)) {
				if ($this->_haserrorfunctions) {
					$this->_errorMsg = db2_errormsg();
					$this->_errorCode = db2_error();
				}
				return false;
			}
		
		} else if (is_array($sql)) {
			$stmtid = $sql[1];
			if (!db2_execute($stmtid)) {
				if ($this->_haserrorfunctions) {
					$this->_errorMsg = db2_errormsg();
					$this->_errorCode = db2_error();
				}
				return false;
			}
		} else
			$stmtid = db2_exec($this->_connectionID,$sql);
		
		$this->_lastAffectedRows = 0;
		if ($stmtid) {
			if (@db2_num_fields($stmtid) == 0) {
				$this->_lastAffectedRows = db2_num_rows($stmtid);
				$stmtid = true;
			} else {
				$this->_lastAffectedRows = 0;
			}
			
			if ($this->_haserrorfunctions) {
				$this->_errorMsg = '';
				$this->_errorCode = 0;
			} else
				$this->_errorMsg = isset($php_errormsg) ? $php_errormsg : '';
		} else {
			if ($this->_haserrorfunctions) {
				$this->_errorMsg = db2_stmt_errormsg();
				$this->_errorCode = db2_stmt_error();
			} else
				$this->_errorMsg = isset($php_errormsg) ? $php_errormsg : '';
		}
		return $stmtid;
	}

	/*
		Insert a null into the blob field of the table first.
		Then use UpdateBlob to store the blob.
		
		Usage:
		 
		$conn->Execute('INSERT INTO blobtable (id, blobcol) VALUES (1, null)');
		$conn->UpdateBlob('blobtable','blobcol',$blob,'id=1');
	*/
	function UpdateBlob($table,$column,$val,$where,$blobtype='BLOB')
	{
		return $this->Execute("UPDATE $table SET $column=? WHERE $where",array($val)) != false;
	}
	
	// returns true or false
	function _close()
	{
		$ret = @db2_close($this->_connectionID);
		$this->_connectionID = false;
		return $ret;
	}

	function _affectedrows()
	{
		return $this->_lastAffectedRows;
	}
	
}
	
/*--------------------------------------------------------------------------------------
	 Class Name: Recordset
--------------------------------------------------------------------------------------*/

class ADORecordSet_db2 extends ADORecordSet {	
	
	var $bind = false;
	var $databaseType = "db2";		
	var $dataProvider = "db2";
	var $useFetchArray;
	
	function ADORecordSet_db2($id,$mode=false)
	{
		if ($mode === false) {  
			global $ADODB_FETCH_MODE;
			$mode = $ADODB_FETCH_MODE;
		}
		$this->fetchMode = $mode;
		
		$this->_queryID = $id;
	}


	// returns the field object
	function &FetchField($fieldOffset = -1) 
	{
		
		$off=$fieldOffset+1; // offsets begin at 1
		
		$o= new ADOFieldObject();
		$o->name = @db2_field_name($this->_queryID,$off);
		$o->type = @db2_field_type($this->_queryID,$off);
		$o->max_length = db2_field_width($this->_queryID,$off);
		if (ADODB_ASSOC_CASE == 0) $o->name = strtolower($o->name);
		else if (ADODB_ASSOC_CASE == 1) $o->name = strtoupper($o->name);
		return $o;
	}
	
	/* Use associative array to get fields array */
	function Fields($colname)
	{
		if ($this->fetchMode & ADODB_FETCH_ASSOC) return $this->fields[$colname];
		if (!$this->bind) {
			$this->bind = array();
			for ($i=0; $i < $this->_numOfFields; $i++) {
				$o = $this->FetchField($i);
				$this->bind[strtoupper($o->name)] = $i;
			}
		}

		 return $this->fields[$this->bind[strtoupper($colname)]];
	}
	
		
	function _initrs()
	{
	global $ADODB_COUNTRECS;
		$this->_numOfRows = ($ADODB_COUNTRECS) ? @db2_num_rows($this->_queryID) : -1;
		$this->_numOfFields = @db2_num_fields($this->_queryID);
		// some silly drivers such as db2 as/400 and intersystems cache return _numOfRows = 0
		if ($this->_numOfRows == 0) $this->_numOfRows = -1;
	}	
	
	function _seek($row)
	{
		return false;
	}
	
	// speed up SelectLimit() by switching to ADODB_FETCH_NUM as ADODB_FETCH_ASSOC is emulated
	function &GetArrayLimit($nrows,$offset=-1) 
	{
		if ($offset <= 0) {
			$rs =& $this->GetArray($nrows);
			return $rs;
		}
		$savem = $this->fetchMode;
		$this->fetchMode = ADODB_FETCH_NUM;
		$this->Move($offset);
		$this->fetchMode = $savem;
		
		if ($this->fetchMode & ADODB_FETCH_ASSOC) {
			$this->fields =& $this->GetRowAssoc(ADODB_ASSOC_CASE);
		}
		
		$results = array();
		$cnt = 0;
		while (!$this->EOF && $nrows != $cnt) {
			$results[$cnt++] = $this->fields;
			$this->MoveNext();
		}
		
		return $results;
	}
	
	
	function MoveNext() 
	{
		if ($this->_numOfRows != 0 && !$this->EOF) {		
			$this->_currentRow++;
			
			$this->fields = @db2_fetch_array($this->_queryID);
			if ($this->fields) {
				if ($this->fetchMode & ADODB_FETCH_ASSOC) {
					$this->fields =& $this->GetRowAssoc(ADODB_ASSOC_CASE);
				}
				return true;
			}
		}
		$this->fields = false;
		$this->EOF = true;
		return false;
	}	
	
	function _fetch()
	{

		$this->fields = db2_fetch_array($this->_queryID);
		if ($this->fields) {
			if ($this->fetchMode & ADODB_FETCH_ASSOC) {
				$this->fields =& $this->GetRowAssoc(ADODB_ASSOC_CASE);
			}
			return true;
		}
		$this->fields = false;
		return false;
	}
	
	function _close() 
	{
		return @db2_free_result($this->_queryID);		
	}

}
?>
