<?php
 namespace amqphp; use amqphp\protocol; use amqphp\wire; class StreamSocket { const READ_SELECT = 1; const WRITE_SELECT = 2; const READ_LENGTH = 4096; const BLOCK_TIMEOUT = 5; private static $All = array(); private static $Counter = 0; private $host, $port, $vhost; private $id, $connected, $interrupt = false; private $flags; private $stfp; private $errFlag = 0; function __construct ($params, $flags, $vhost) { $this->url = $params['url']; $this->context = isset($params['context']) ? $params['context'] : array(); $this->flags = $flags ? $flags : array(); $this->id = ++self::$Counter; $this->vhost = $vhost; } function getVHost () { return $this->vhost; } function getCK () { return md5(sprintf("%s:%s:%s", $this->url, $this->getFlags(), $this->vhost)); } private function getFlags () { $flags = STREAM_CLIENT_CONNECT; foreach ($this->flags as $f) { $flags |= constant($f); } return $flags; } function connect () { $context = stream_context_create($this->context); $flags = $this->getFlags(); $this->sock = stream_socket_client($this->url, $errno, $errstr, ini_get("default_socket_timeout"), $flags, $context); $this->stfp = ftell($this->sock); if (! $this->sock) { throw new \Exception("Failed to connect stream socket {$this->url}, ($errno, $errstr): flags $flags", 7568); } else if (($flags & STREAM_CLIENT_PERSISTENT) && $this->stfp > 0) { foreach (self::$All as $sock) { if ($sock !== $this && $sock->getCK() == $this->getCK()) { $this->sock = null; throw new \Exception(sprintf("Stream socket connection created a new wrapper object for " . "an existing persistent connection on URL %s", $this->url), 8164); } } } if (! stream_set_blocking($this->sock, 0)) { throw new \Exception("Failed to place stream connection in non-blocking mode", 2795); } $this->connected = true; self::$All[] = $this; } function isReusedPSock () { return ($this->stfp > 0); } function getConnectionStartFP () { return $this->stfp; } function tell () { return ftell($this->sock); } function select ($tvSec, $tvUsec = 0, $rw = self::READ_SELECT) { $read = $write = $ex = null; if ($rw & self::READ_SELECT) { $read = $ex = array($this->sock); } if ($rw & self::WRITE_SELECT) { $write = array($this->sock); } if (! $read && ! $write) { throw new \Exception("Select must read and/or write", 9864); } $this->interrupt = false; $ret = stream_select($read, $write, $ex, $tvSec, $tvUsec); if ($ret === false) { $this->interrupt = true; } return $ret; } static function Zelekt (array $incSet, $tvSec, $tvUsec) { $write = null; $read = $all = array(); foreach (self::$All as $i => $o) { if (in_array($o->id, $incSet)) { $read[$i] = $all[$i] = $o->sock; } } $ex = $read; $ret = false; if ($read) { $ret = stream_select($read, $write, $ex, $tvSec, $tvUsec); } if ($ret === false) { return false; } $_read = $_ex = array(); foreach ($read as $sock) { if (false !== ($key = array_search($sock, $all, true))) { $_read[] = self::$All[$key]; } } foreach ($ex as $k => $sock) { if (false !== ($key = array_search($sock, $all, true))) { $_ex[] = self::$All[$key]; } } return array($ret, $_read, $_ex); } function selectInterrupted () { return $this->interrupt; } function lastError () { return $this->errFlag; } function clearErrors () { $this->errFlag = 0; } function strError () { switch ($this->errFlag) { case 1: return "Read error detected"; case 2: return "Write error detected"; case 3: return "Read and write errors detected"; default: return "No error detected"; } } function readAll ($readLen = self::READ_LENGTH) { $buff = ''; do { $buff .= $chk = fread($this->sock, $readLen); $smd = stream_get_meta_data($this->sock); $readLen = min($smd['unread_bytes'], $readLen); } while ($chk !== false && $smd['unread_bytes'] > 0); if (! $chk) { trigger_error("Stream fread returned false", E_USER_WARNING); $this->errFlag |= 1; } if (DEBUG) { echo "\n<read>\n"; echo wire\Hexdump::hexdump($buff); } return $buff; } function read () { $buff = ''; $select = $this->select(self::BLOCK_TIMEOUT); if ($select === false) { return false; } else if ($select > 0) { $buff = $this->readAll(); } return $buff; } function getUnreadBytes () { return ($smd = stream_get_meta_data($this->sock)) ? $smd['unread_bytes'] : false; } function eof () { return feof($this->sock); } function write ($buff) { if (! $this->select(self::BLOCK_TIMEOUT, 0, self::WRITE_SELECT)) { trigger_error('StreamSocket select failed for write (stream socket err: "' . $this->strError() . ')', E_USER_WARNING); return 0; } $bw = 0; $contentLength = strlen($buff); if ($contentLength == 0) { return 0; } while (true) { if (DEBUG) { echo "\n<write>\n"; echo wire\Hexdump::hexdump($buff); } if (($tmp = fwrite($this->sock, $buff)) === false) { $this->errFlag |= 2; throw new \Exception(sprintf("\nStream write failed (error): %s\n", $this->strError()), 7854); } else if ($tmp === 0) { throw new \Exception(sprintf("\nStream write failed (zero bytes written): %s\n", $this->strError()), 7855); } $bw += $tmp; if ($bw < $contentLength) { $buff = substr($buff, $bw); } else { break; } } fflush($this->sock); return $bw; } function close () { $this->connected = false; fclose($this->sock); $this->detach(); } private function detach () { if (false !== ($k = array_search($this, self::$All))) { unset(self::$All[$k]); } } function getId () { return $this->id; } } 