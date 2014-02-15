<?php
require_once 'ConfigLoader.php';

/**
 * Sendmail Wrapper by Onlime Webhosting
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright  Copyright (c) 2007-2014 Onlime Webhosting (http://www.onlime.ch)
 */
class SendmailThrottle extends StdinMailParser
{
    /**
     * @var StdClass
     */
    protected $_conf;

    /**
     * @var PDO
     */
    protected $_pdo;

    /**
     * Constructor
     */
    public function __construct()
    {
        // load configuration
        $configLoader = new ConfigLoader();
        $this->_conf  = $configLoader->getConfig();

        parent::__construct();
    }

    /**
     * Destructor
     * close the PDO database connection
     */
    public function __destruct()
    {
        $this->_pdo = null;
    }

    /**
     * Create PDO database connection
     *
     * @throws PDOException
     */
    protected function _connect()
    {
        $this->_pdo = new PDO(
            $this->_conf->db->dsn,
            $this->_conf->db->user,
            $this->_conf->db->pass
        );
    }

    /**
     * throttling
     *
     * status code 0: limit not reached, ok
     * status code 1: limit reached, sending notification to admin
     * status code 2: limit succeeded, do not warn admin multiple times
     *
     * @param string $username
     * @param int $rcptCount number of recipients
     * @return int exit status code (0 = success)
     */
    public function run($username, $rcptCount)
    {
        try {
            // connect to DB
            $this->_connect();

            $sql = 'SELECT * FROM throttle WHERE username = :username';
            $stmt = $this->_pdo->prepare($sql);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            $obj = $stmt->fetchObject();
            if ($obj) {
                $countMax = $obj->count_max;
                $countCur = ++$obj->count_cur; // raise by 1
                $countTot = ++$obj->count_tot; // raise by 1
                $rcptMax  = $obj->rcpt_max;
                $rcptCur  = $obj->rcpt_cur + $rcptCount; // raise by number of recipients
                $rcptTot  = $obj->rcpt_tot + $rcptCount; // raise by number of recipients

                // check email count
                if ($countCur <= $obj->count_max) {
                    $status = 0;
                } else {
                    $status = ($countCur == ($obj->count_max + 1)) ? 1 : 2;
                }
                // check recipient count
                if ($rcptCur <= $obj->rcpt_max) {
                    $status = 0;
                } else {
                    $status = ($rcptCur == ($obj->rcpt_max + 1)) ? 1 : 2;
                }

                // reset counters on new day (after midnight)
                $dateUpdated = new DateTime($obj->updated_ts);
                $dateCurrent = new DateTime();
                $sameDay = ($dateUpdated->format('Y-m-d') == $dateCurrent->format('Y-m-d'));
                if (!$sameDay) {
                    $countCur = 1;
                    $rcptCur  = 1;
                }

                $sql = 'UPDATE throttle SET updated_ts = NOW(), count_cur = :countCur, count_tot = :countTot,
                        rcpt_cur = :rcptCur, rcpt_tot = :rcptTot, status = :status
                        WHERE username = :username';
                $stmt = $this->_pdo->prepare($sql);
                $stmt->bindParam(':countCur', $countCur, PDO::PARAM_INT);
                $stmt->bindParam(':countTot', $countTot, PDO::PARAM_INT);
                $stmt->bindParam(':rcptCur' , $rcptCur , PDO::PARAM_INT);
                $stmt->bindParam(':rcptTot' , $rcptTot , PDO::PARAM_INT);
                $stmt->bindParam(':status'  , $status  , PDO::PARAM_INT);
                $stmt->bindParam(':username', $username);
                $stmt->execute();
                $id = $obj->id;
            } else {
                $countMax = $this->_conf->throttle->countMax;
                $countCur = 1;
                $countTot = 1;
                $rcptMax  = $this->_conf->throttle->rcptMax;
                $rcptCur  = $rcptCount;
                $rcptTot  = $rcptCount;

                $sql = 'INSERT INTO throttle (updated_ts, username, count_max, rcpt_max, rcpt_cur, rcpt_tot)
                        VALUES (NOW(), :username, :countMax, :rcptMax, :rcptCur, :rcptTot)';
                $stmt = $this->_pdo->prepare($sql);
                $stmt->bindParam(':username', $username);
                $stmt->bindParam(':countMax', $countMax, PDO::PARAM_INT);
                $stmt->bindParam(':rcptMax' , $rcptMax , PDO::PARAM_INT);
                $stmt->bindParam(':rcptCur' , $rcptCur , PDO::PARAM_INT);
                $stmt->bindParam(':rcptTot' , $rcptTot , PDO::PARAM_INT);
                $stmt->execute();
                $id = $this->_pdo->lastInsertId();
                $status = 0;
            }

            // syslogging
            $syslogMsg =  sprintf('%s: user=%s (%s:%s), rcpts=%s, status=%s, command=%s, ' .
                'count_max=%s, count_cur=%s, count_tot=%s, ' .
                'rcpt_max=%s, rcpt_cur=%s, rcpt_tot=%s',
                $this->_conf->throttle->syslogPrefix,
                $username,
                $_SERVER['SUDO_UID'],
                $_SERVER['SUDO_GID'],
                $rcptCount,
                $status,
                $_SERVER['SUDO_COMMAND'],
                $countMax,
                $countCur,
                $countTot,
                $rcptMax,
                $rcptCur,
                $rcptTot
            );
            syslog(LOG_INFO, $syslogMsg);

            // Report message limit succeeded to administrator
            if ($status == 1) {
                // Do not report on status code 2, as the admin only wants to get
                // notified once!
                mail(
                    $this->_conf->global->adminTo,
                    $this->_conf->throttle->adminSubject,
                    $syslogMsg,
                    "From: " . $this->_conf->global->adminFrom
                );
            }

            // write to db log
            $this->_logMessage($id, $username, $rcptCount, $status);

            // return status code
            return $status;

        } catch (PDOException $e) {
            syslog(LOG_WARNING, sprintf('%s: PDOException: %s', $this->_conf->throttle->syslogPrefix, $e->getMessage()));
            return 3;
        }
    }

    /**
     * Insert metadata of each message into messages table,
     * for logging purposes.
     *
     * @param int $throttleId
     * @param string $username
     * @param int $rcptCount
     * @param int $status
     */
    protected function _logMessage($throttleId, $username, $rcptCount, $status)
    {
        $headerArr = $this->getParsedHeaderArr();

        $sql = 'INSERT INTO messages (throttle_id, username, uid, gid, rcpt_count, status, msgid, from, to, cc, bcc, subject, site, client, script)
                   VALUES (:throttleId, :username, :uid, :gid, :rcptCount, :status, :msgid, :from, :to, :cc, :bcc, :subject, :site, :client, :script)';
        $stmt = $this->_pdo->prepare($sql);
        $stmt->bindParam(':throttleId', $throttleId);
        $stmt->bindParam(':username'  , $username);
        $stmt->bindParam(':uid'       , $_SERVER['SUDO_UID']);
        $stmt->bindParam(':gid'       , $_SERVER['SUDO_GID']);
        $stmt->bindParam(':rcptCount' , $rcptCount);
        $stmt->bindParam(':status'    , $status);
        $stmt->bindParam(':msgid'     , $headerArr['x-meta-msgid']);
        $stmt->bindParam(':from'      , imap_utf8($headerArr['from']));
        $stmt->bindParam(':to'        , imap_utf8($headerArr['to']));
        $stmt->bindParam(':cc'        , imap_utf8($headerArr['cc']));
        $stmt->bindParam(':bcc'       , imap_utf8($headerArr['bcc']));
        $stmt->bindParam(':subject'   , imap_utf8($headerArr['subject']));
        $stmt->bindParam(':site'      , $headerArr['x-meta-site']);
        $stmt->bindParam(':client'    , $headerArr['x-meta-client']);
        $stmt->bindParam(':script'    , $headerArr['x-meta-script']);
        $stmt->execute();
    }
}
