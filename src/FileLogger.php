<?php
/**
 * FileLogger.php
 *
 * This file is part of InitPHP.
 *
 * @author     Muhammet ŞAFAK <info@muhammetsafak.com.tr>
 * @copyright  Copyright © 2022 InitPHP
 * @license    http://initphp.github.io/license.txt  MIT
 * @version    1.0
 * @link       https://www.muhammetsafak.com.tr
 */

namespace InitPHP\Logger;

use \Psr\Log\AbstractLogger;
use \Psr\Log\LoggerInterface;

use const PHP_EOL;
use const FILE_APPEND;

use function strtoupper;
use function date;

class FileLogger extends \Psr\Log\AbstractLogger implements \Psr\Log\LoggerInterface
{
    use HelperTrait;

    /** @var string  */
    protected $path;

    /**
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->path = $this->interpolate($options['path'], array(
            'year'      => date('Y'),
            'month'     => date('m'),
            'day'       => date('d'),
            'hour'      => date('H'),
            'minute'    => date('i'),
            'second'    => date('s')
        ));
    }

    /**
     * @inheritDoc
     */
    public function log($level, $message, array $context = array())
    {
        $this->logLevelVerify($level);
        $msg = PHP_EOL . $this->getDate('c') . ' ['
            . strtoupper($level)
            . '] ' . $this->interpolate($message, $context);
        @file_put_contents($this->path, $msg, FILE_APPEND);
    }

}
