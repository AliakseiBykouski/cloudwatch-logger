<?php

namespace CreationMedia\CloudWatchLogger;

final class Logger
{
    const DEBUG = 100;
    const INFO = 200;
    const NOTICE = 250;
    const WARNING = 300;
    const ERROR = 400;
    const CRITICAL = 500;
    const ALERT = 550;
    const EMERGENCY = 600;

    private static $instance;
    private static $registry;
    private static $level = 200;
    private static $region = 'eu-west-1';
    private static $client;

    private function __construct(){}

    public static function getInstance()
    {
        if (self::$instance==null) {
            self::$instance = new Logger();
        }
        return self::$instance;
    }
    private function __clone(){ }

    private function __wakeup() { }

    static public function set($key,$obj) {
        return self::$registry[$key]=$obj;
    }

    static public function get($key) {
        return self::$registry[$key];
    }

    static function exists($key) {
        return isset(self::$registry[$key]);
    }

    public static function getInstanceId()
    {
        $hv_uuid = @exec('cat /sys/hypervisor/uuid');
        if ($hv_uuid) {
            return file_get_contents("http://169.254.169.254/latest/meta-data/instance-id");
        }
        return gethostname();
    }


    public function setLogName($name)
    {
        if (substr($name, 0, 7) == 'file://') {
            self::set('LOG_PATH',substr($name, 7) );
            self::set('LOG_HANDLER','logFile' );
        } else {
            self::set('LOG_GROUP',$name );
            self::set('LOG_HANDLER','logCloudWatch' );
        }
    }

    public function setLogLevel($level)
    {
        self::$level = intval($level);
    }

    public static function getLevelName($level)
    {
        $qt = new \ReflectionClass(__CLASS__);
        $types = $qt->getConstants();
        return array_search($level, $types);
    }

    static private function getPath()
    {
        $lineNumber = 0;
        $debug = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $line = $debug[2];
        if ($line && array_key_exists('line', $line)) {
            $lineNumber = $line['line'];
        }
        $caller = $debug[3];

        if (!$caller) {
            $logname = 'Bootstrap';
        } elseif (array_key_exists('class', $caller)) {
            $logname = $caller['class'];
        } elseif (array_key_exists('file', $caller)) {
            $logname = ucfirst(basename($caller['file'], '.php'));
        } else {
            $logname = 'Unknown';
        }

        //ltrim not working so
        if (self::get('STRIP_NAMESPACE') && substr($logname, 0, strlen(self::get('STRIP_NAMESPACE'))) == self::get('STRIP_NAMESPACE')) {
            $logname = substr($logname, strlen(self::get('STRIP_NAMESPACE')));
        }
        return sprintf('%s(%d)', $logname, $lineNumber);
    }

    static private function initializeCloudWatch()
    {
        if (self::$client) {
            return;
        }
        $sdkParams = [
            'region' => self::$region,
            'version' => 'latest',
            'credentials' => self::get('AWS_CREDENTIALS')
        ];

        $client = new \Aws\CloudWatchLogs\CloudWatchLogsClient($sdkParams);
        $existingGroups = $client->DescribeLogGroups(['logGroupNamePrefix' => self::get('LOG_GROUP')])->get('logGroups');
        $existingGroupsNames = array_map(
            function ($group) {
                return $group['logGroupName'];
            },
            $existingGroups
        );
        // create group and set retention policy if not created yet
        if (!in_array(self::get('LOG_GROUP'), $existingGroupsNames, true)) {
            $client->createLogGroup(['logGroupName' => self::get('LOG_GROUP')]);
            $client->putRetentionPolicy(
                [
                    'logGroupName' => self::get('LOG_GROUP'),
                    'retentionInDays' => 90,
                ]
            );
        }
        $existingStreams = $client->describeLogStreams(
            [
                'logGroupName' => self::get('LOG_GROUP'),
                'logStreamNamePrefix' => self::get('LOG_STREAM')
            ]
        )->get('logStreams');

        $existingStreamsNames = array_map(
            function ($stream) {
                return $stream['logStreamName'];
            },
            $existingStreams
        );

        if (!in_array(self::get('LOG_STREAM'), $existingStreamsNames, true)) {
            $client->createLogStream(
                [
                    'logGroupName' => self::get('LOG_GROUP'),
                    'logStreamName' => self::get('LOG_STREAM')
                ]
            );
        }
        self::$client =  $client;

    }

    static private function getSequenceToken()
    {
        $streams = self::$client->describeLogStreams(
            [
                'logGroupName' => self::get('LOG_GROUP'),
                'logStreamNamePrefix' => self::get('LOG_STREAM')
            ]
        )->get('logStreams');
        foreach($streams as $stream) {
            if ($stream['logStreamName'] === self::get('LOG_STREAM') && isset($stream['uploadSequenceToken'])) {
                return $stream['uploadSequenceToken'];
            }
        }
        return false;
    }

    static private function logCloudWatch($level, $message, $context)
    {
        self::initializeCloudWatch();
        $data = [
            'logGroupName' => self::get('LOG_GROUP'),
            'logStreamName' => self::get('LOG_STREAM'),
            'logEvents' => [
                [
                    'message'=>sprintf('%s %s',$message, json_encode($context)),
                    'timestamp' =>  time()*1000],
            ],
            'sequenceToken' => self::getSequenceToken()
        ];
        $response = self::$client->putLogEvents($data);
    }

    static private function logFile($level, $message, $context)
    {
        //add date
        $message = sprintf('[%s] %s', date('Y-m-d H:i:s'), $message);
        $line = sprintf("%s %s\n", $message, json_encode($context));
        file_put_contents(self::get('LOG_PATH'), $line, FILE_APPEND);
    }

    public static function doLog($level, $msg, $context = [], $color = null)
    {
        if (self::$level > $level) {
            return;
        }
        //cast context to an array
        $context = (array) $context;

        $context['log_level'] = self::getLevelName($level);
        $context['instance'] = self::getInstanceId();

        $message = sprintf('%s %s %s',
            $context['log_level'],
            self::getPath(),
            $msg
        );

        if ($color && self::get('LOG_HANDLER') == 'logFile') {
            $message = chr(27) . "$color" . "$message" . chr(27) . "[0m";
        }
        call_user_func_array(['self', self::get('LOG_HANDLER')], [$level, $message, $context]);
    }
    public static function debug($msg, $context = [])
    {
        self::doLog(self::DEBUG, $msg, $context);
    }

    public static function info($msg, $context = [])
    {
        self::doLog(self::INFO, $msg, $context);
    }

    public static function notice($msg, $context = [])
    {
        self::doLog(self::NOTICE, $msg, $context);
    }

    public static function warning($msg, $context = [])
    {
        self::doLog(self::WARNING, $msg, $context, "[1;33m");
    }

    public static function error($msg, $context = [])
    {
        self::doLog(self::ERROR, $msg, $context, "[0;31m");
    }

    public static function critical($msg, $context = [])
    {
        self::doLog(self::CRITICAL, $msg, $context, "[41m");
        //todo:send email
    }

    public static function alert($msg, $context = [])
    {
        self::doLog(self::ALERT, $msg, $context, "[41m");
        //todo: send sms
    }


    public static function emergency($msg, $context = [])
    {
        self::doLog(self::EMERGENCY, $msg, $context, "[41m");
        //send sms
    }

    public static function abort($msg, $context = [])
    {
        self::doLog(self::EMERGENCY, $msg, $context, "[41m");
        //send email
        die($msg);
    }

}
