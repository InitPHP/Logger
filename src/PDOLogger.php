<?php
/**
 * PDOLogger.php
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
use \Psr\Log\InvalidArgumentException;
use \Psr\Log\LoggerInterface;
use \PDO;

use function strtoupper;

class PDOLogger extends \Psr\Log\AbstractLogger implements \Psr\Log\LoggerInterface
{
    use HelperTrait;

    /** @var PDO */
    protected $pdo;

    /** @var string */
    protected $table;

    /**
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        if(!($options['pdo'] instanceof PDO)){
            throw new \InvalidArgumentException('It must be a PDO object.');
        }
        if(!is_string($options['table'])){
            throw new \InvalidArgumentException('The name of the table where the logs will be kept must be specified as a string.');
        }
        $this->pdo = $options['pdo'];
        $this->table = $options['table'];
    }

    public function __destruct()
    {
        $this->pdo = null;
    }

    /**
     * @inheritDoc
     */
    public function log($level, $message, array $context = array())
    {
        $this->logLevelVerify($level);
        $date = $this->getDate('Y-m-d H:i:s');
        $level = strtoupper($level);
        $msg = $this->interpolate($message, $context);

        $sql = "INSERT INTO " . $this->table . " (level, message, date) VALUES (?,?,?)";
        $query = $this->pdo->prepare($sql);
        $query->execute(array($level, $msg, $date));
    }
}