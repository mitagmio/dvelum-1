<?php
/**
 *  DVelum project https://github.com/dvelum/dvelum
 *  Copyright (C) 2011-2017  Kirill Yegorov
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Dvelum\Log;

class File extends \Psr\Log\AbstractLogger implements LogInterface
{
    protected $file;

    /**
     * Get File name
     * @return string
     */
    public function getFileName() : string
    {
        return $this->file;
    }

    /**
     * @param string $file - logfile path
     */
    public function __construct($file)
    {
        $this->file = $file;
    }

    /**
     * @param mixed $level
     * @param string $message
     * @param array $context
     */
    public function log($level, $message, array $context = [])
    {
        $message = '['.date('d.m.Y H:i:s') . '] ('.$level.') '. $message . ' '.json_encode($context)."\n";
        file_put_contents($this->file, $message , FILE_APPEND);
    }
}