<?php if (!defined('NEOFRAG_CMS')) exit;
/**************************************************************************
Copyright © 2015 Michaël BILCOT & Jérémy VALENTIN

This file is part of NeoFrag.

NeoFrag is free software: you can redistribute it and/or modify
it under the terms of the GNU Lesser General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

NeoFrag is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public License
along with NeoFrag. If not, see <http://www.gnu.org/licenses/>.
**************************************************************************/

#
# Portable PHP password hashing framework.
#
# Version 0.3 / genuine.
#
# Written by Solar Designer <solar at openwall.com> in 2004-2006 and placed in
# the public domain.  Revised in subsequent years, still public domain.
#
# There's absolutely no warranty.
#
# The homepage URL for this framework is:
#
#	http://www.openwall.com/phpass/
#
# Please be sure to update the Version line if you edit this file in any way.
# It is suggested that you leave the main version number intact, but indicate
# your project name (after the slash) and add your own revision information.
#
# Please do not change the "private" password hashing method implemented in
# here, thereby making your hashes incompatible.  However, if you must, please
# change the hash type identifier (the "$P$") to something different.
#
# Obviously, since this code is in the public domain, the above are not
# requirements (there can be none), but merely suggestions.
#

class Password extends Library
{
	private $_itoa64;
	private $_iteration_count_log2;
	private $_portable_hashes;
	private $_random_state;
	private $_salt;

	public function __construct($config)
	{
		parent::__construct();

		$this->_itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
		$this->_salt  = $config['salt'];

		$iteration_count_log2 = $config['iteration_count_log2'];

		if ($iteration_count_log2 < 4 || $iteration_count_log2 > 31)
		{
			$iteration_count_log2 = 8;
		}

		$this->_iteration_count_log2 = $iteration_count_log2;

		$this->_portable_hashes = $config['portable_hashes'];

		$this->_random_state = microtime();

		if (function_exists('getmypid'))
		{
			$this->_random_state .= getmypid();
		}
	}

	private function _get_random_bytes($count)
	{
		$output = '';

		if (is_readable('/dev/urandom') && ($fh = @fopen('/dev/urandom', 'rb')))
		{
			$output = fread($fh, $count);
			fclose($fh);
		}

		if (strlen($output) < $count)
		{
			$output = '';

			for ($i = 0; $i < $count; $i += 16)
			{
				$this->_random_state = md5(microtime().$this->_random_state);
				$output .= pack('H*', md5($this->_random_state));
			}

			$output = substr($output, 0, $count);
		}

		return $output;
	}

	private function _encode64($input, $count)
	{
		$output = '';
		$i      = 0;

		do
		{
			$value = ord($input[$i++]);
			$output .= $this->_itoa64[$value & 0x3f];

			if ($i < $count)
			{
				$value |= ord($input[$i]) << 8;
			}

			$output .= $this->_itoa64[($value >> 6) & 0x3f];

			if ($i++ >= $count)
			{
				break;
			}

			if ($i < $count)
			{
				$value |= ord($input[$i]) << 16;
			}

			$output .= $this->_itoa64[($value >> 12) & 0x3f];

			if ($i++ >= $count)
			{
				break;
			}

			$output .= $this->_itoa64[($value >> 18) & 0x3f];
		}
		while ($i < $count);

		return $output;
	}

	private function _gensalt_private($input)
	{
		$output = '$P$';
		$output .= $this->_itoa64[min($this->_iteration_count_log2 + ((PHP_VERSION >= '5') ? 5 : 3), 30)];
		$output .= $this->_encode64($input, 6);

		return $output;
	}

	private function _crypt_private($password, $setting)
	{
		$output = '*0';

		if (substr($setting, 0, 2) == $output)
		{
			$output = '*1';
		}

		$id = substr($setting, 0, 3);
		# We use "$P$", phpBB3 uses "$H$" for the same thing
		if ($id != '$P$' && $id != '$H$')
		{
			return $output;
		}

		$count_log2 = strpos($this->_itoa64, $setting[3]);

		if ($count_log2 < 7 || $count_log2 > 30)
		{
			return $output;
		}

		$count = 1 << $count_log2;

		$salt = substr($setting, 4, 8);

		if (strlen($salt) != 8)
		{
			return $output;
		}

		# We're kind of forced to use MD5 here since it's the only
		# cryptographic primitive available in all versions of PHP
		# currently in use.  To implement our own low-level crypto
		# in PHP would result in much worse performance and
		# consequently in lower iteration counts and hashes that are
		# quicker to crack (by non-PHP code).
		if (PHP_VERSION >= '5')
		{
			$hash = md5($salt . $password, TRUE);

			do
			{
				$hash = md5($hash . $password, TRUE);
			}
			while (--$count);
		}
		else
		{
			$hash = pack('H*', md5($salt . $password));

			do
			{
				$hash = pack('H*', md5($hash . $password));
			}
			while (--$count);
		}

		$output = substr($setting, 0, 12);
		$output .= $this->_encode64($hash, 16);

		return $output;
	}

	private function _gensalt_extended($input)
	{
		$count_log2 = min($this->_iteration_count_log2 + 8, 24);
		# This should be odd to not reveal weak DES keys, and the
		# maximum valid value is (2**24 - 1) which is odd anyway.
		$count = (1 << $count_log2) - 1;

		$output = '_';
		$output .= $this->_itoa64[$count & 0x3f];
		$output .= $this->_itoa64[($count >> 6) & 0x3f];
		$output .= $this->_itoa64[($count >> 12) & 0x3f];
		$output .= $this->_itoa64[($count >> 18) & 0x3f];

		$output .= $this->_encode64($input, 3);

		return $output;
	}

	private function _gensalt_blowfish($input)
	{
		# This one needs to use a different order of characters and a
		# different encoding scheme from the one in _encode64() above.
		# We care because the last character in our encoded string will
		# only represent 2 bits.  While two known implementations of
		# bcrypt will happily accept and correct a salt string which
		# has the 4 unused bits set to non-zero, we do not want to take
		# chances and we also do not want to waste an additional byte
		# of entropy.
		$itoa64 = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

		$output = '$2a$';
		$output .= chr(ord('0') + $this->_iteration_count_log2 / 10);
		$output .= chr(ord('0') + $this->_iteration_count_log2 % 10);
		$output .= '$';

		$i = 0;
		do
		{
			$c1 = ord($input[$i++]);
			$output .= $itoa64[$c1 >> 2];
			$c1 = ($c1 & 0x03) << 4;

			if ($i >= 16)
			{
				$output .= $itoa64[$c1];
				break;
			}

			$c2 = ord($input[$i++]);
			$c1 |= $c2 >> 4;
			$output .= $itoa64[$c1];
			$c1 = ($c2 & 0x0f) << 2;

			$c2 = ord($input[$i++]);
			$c1 |= $c2 >> 6;
			$output .= $itoa64[$c1];
			$output .= $itoa64[$c2 & 0x3f];
		}
		while (1);

		return $output;
	}

	public function encrypt($password)
	{
		$password = $this->_salt.$password;
		$random   = '';

		/*
		if (CRYPT_BLOWFISH == 1 && !$this->_portable_hashes)
		{
			$random = $this->_get_random_bytes(16);
			$hash =	crypt($password, $this->_gensalt_blowfish($random));

			if (strlen($hash) == 60)
			{
				return $hash;
			}
		}

		if (CRYPT_EXT_DES == 1 && !$this->_portable_hashes)
		{
			if (strlen($random) < 3)
			{
				$random = $this->_get_random_bytes(3);
			}

			$hash = crypt($password, $this->_gensalt_extended($random));

			if (strlen($hash) == 20)
			{
				return $hash;
			}
		}*/

		if (strlen($random) < 6)
		{
			$random = $this->_get_random_bytes(6);
		}

		$hash = $this->_crypt_private($password, $this->_gensalt_private($random));

		if (strlen($hash) == 34)
		{
			return $hash;
		}

		# Returning '*' on error is safe here, but would _not_ be safe
		# in a crypt(3)-like function used _both_ for generating new
		# hashes and for validating passwords against existing hashes.
		return '*';
	}

	public function is_valid($password, $stored_hash, $salt = TRUE)
	{
		if ($salt)
		{
			$password = $this->_salt.$password;
		}

		$hash = $this->_crypt_private($password, $stored_hash);

		if ($hash[0] == '*')
		{
			$hash = crypt($password, $stored_hash);
		}

		return $hash == $stored_hash;
	}
}

/*
NeoFrag Alpha 0.1
./neofrag/libraries/password.php
*/