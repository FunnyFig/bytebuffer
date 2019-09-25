<?php

namespace FunnyFig;

class ByteBuffer {
	// is little machine endianness?
	protected static $is_le = null;

	// is big-endian bytes underlying?
	protected $buf_be = false;

	protected $buf;
	// put pointer
	protected $p = 0;
	// get pointer
	protected $g = 0;

	const BEG = 1;
	const CUR = 0;
	const END = -1;

	function __construct($init_val='')
	{
		if (is_int($init_val)) {
			if ($init_val<0) {
				throw new \InvalidArgumentException();
			}
			$this->buf = str_repeat("\0", $init_val);
			return;
		}

		if (!is_string($init_val)) {
			throw new \InvalidArgumentException();
		}
		$this->buf = $init_val;
		$this->p = strlen($init_val);
	}

	function __destruct() {}
	
	// set/get endianness
	function bigEndian($be=null)
	{
		if ($be === null)
			return $this->buf_be;
		$this->buf_be = (bool)$be;
	}

	// eat up all the bytes
	function reset()
	{
		$this->buf = '';
		$this->p = $this->g = 0;
	}

	function put(string $val)
	{
		if (!$val) return;

		$len = strlen($val);

		if ($this->put_capacity() < $len) {
			$this->grow($len-$this->put_capacity());
		}

		for ($i=0; $i<$len; ++$i)
			$this->buf[$this->p++] = $val[$i];
	}

	function get(int $n_bytes=0)
	{
		$rv = $this->peek($n_bytes);

		$this->g += $n_bytes;
		return $rv;
	}

	function peek(int $n_bytes=0)
	{
		if ($n_bytes<0 || $this->size()<$n_bytes) {
			throw new \OutOfRangeException();
		}

		if (!$n_bytes) $n_bytes = $this->size();

		return substr($this->buf, $this->g, $n_bytes);
	}

	function g_int32()
	{
		$rv = $this->g_uint32();
		// https://stackoverflow.com/questions/24563786/conversion-from-hex-to-signed-dec-in-python/32276534
		return -($rv & 0x80000000) | ($rv & 0x7FFFFFFF);
	}

	function g_uint32()
	{
		return self::read_int($this->get(4), 0, 4, $this->buf_be);
	}

	function p_int32(int $val)
	{
		if ($val<-0x80000000 || 0x7FFFFFFF<$val) {
			throw new \InvalidArgumentException();
		}

		$buf = "\0\0\0\0";
		self::write_int($buf, 0, 4, $val, $this->buf_be);
		$this->put($buf);
	}

	function p_uint32(int $val)
	{
		if ($val<0 || 0xFFFFFFFF<$val) {
			throw new \InvalidArgumentException();
		}

		$buf = "\0\0\0\0";
		self::write_int($buf, 0, 4, $val, $this->buf_be);
		$this->put($buf);
	}

	function tellg()
	{
		return $this->g;
	}

	function seekg(int $d, int $way=self::BEG)
	{
		switch ($way) {
		case self::BEG:
			$g = $d; break;
		case self::CUR:
			$g = $this->g + $d; break;
		case self::END:
			$g = $this->p + $d; break;
		default:
			throw new \InvalidArgumentException();
		}

		if ($g<0 || $this->p<=$g) {
			throw new \OutOfRangeException();
		}
		$this->g = $g;
	}

	function tellp()
	{
		return $this->p;
	}

	function seekp(int $d, int $way=self::BEG)
	{
		switch ($way) {
		case self::BEG:
			$p = $d; break;
		case self::CUR:
			$p = $this->p + $d; break;
		case self::END:
			$p = strlen($this->buf) + $d; break;
		default:
			throw new \InvalidArgumentException();
		}

		if ($this->g>$p) {
			throw new \OutOfRangeException();
		}

		// behave like posix lseek
		if (($ext_sz=$p-strlen($this->buf))>0) {
			$this->buf .= str_repeat("\0", $ext_sz);
		}

		$this->p = $p;
	}	

	// get size: # of bytes written
	function size()
	{
		return $this->p - $this->g;
	}

	// puttable size
	protected function put_capacity()
	{
		return strlen($this->buf) - $this->p;
	}

	protected function grow(int $hint=0)
	{
		$this->adjust_ptr();

		// we grow doubly
		$growsz = max(strlen($this->buf), $hint);
		$this->buf .= str_repeat("\0", $growsz);
	}

	protected function adjust_ptr()
	{
		if ($this->g) {
			$this->buf = substr($this->buf, $this->g);

			$this->p -= $this->g;
			$this->g = 0;
		}
	}

	static function is_le()
	{
		if (self::$is_le === null) {
			self::$is_le = unpack('S', "\x01\x00")[1] === 1;
		}

		return self::$is_le;
	}

	static function read_int( string $buf
				, int $index
				, int $n_bytes
				, bool $be=false )
	{
		if ($index<0 || $n_bytes<1 || $index+$n_bytes>strlen($buf)) {
			throw new \OutOfRangeException();
		}

		$rv = 0;
		if (self::is_le() && !$be) {
			for ($i=0; $i<$n_bytes; ++$i) {
				$rv += ord($buf[$index+$i]) << ($i<<3);
			}
		}
		else {
			for ($i=0; $i<$n_bytes; ++$i) {
				$rv += ord($buf[$index+$n_bytes-1-$i]) << ($i<<3);
			}
		}
		return $rv;
	}

	static function write_int( string &$buf
				 , int $index
				 , int $n_bytes
				 , int $val
				 , bool $be=false )
	{
		if ($index<0 || $n_bytes<1 || strlen($buf)-$index<$n_bytes) {
			throw new \OutOfRangeException();
		}

		if (self::is_le() && !$be) {
			for ($i=0; $i<$n_bytes; ++$i) {
				$buf[$index+$i]
					= chr($val >> ($i<<3));
			}
		}
		else {
			for ($i=0; $i<$n_bytes; ++$i) {
				$buf[$index+$n_bytes-1-$i]
					= chr($val >> ($i<<3));
			}

		}
	}
}

//------------------------------------------------------------------------------
if (!debug_backtrace()) {
	$buf = str_repeat("\0", 4);
	ByteBuffer::write_int($buf, 0, 4, 0x01020304);
	print_r(bin2hex($buf)."\n");

	ByteBuffer::write_int($buf, 0, 4, 0x01020304, true);
	print_r(bin2hex($buf)."\n");

	// int32 -1
	$buf = new ByteBuffer("\xFF\xFF\xFF\xFF");
	echo $buf->g_int32()."\n";

	$buf->reset();
	$buf->p_int32(-2);
	echo $buf->g_int32()."\n";

}
