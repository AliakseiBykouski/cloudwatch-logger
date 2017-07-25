CloudWatch Logger
=========================================
Simple Logger for Creation's F3-based CMS

Usage
```
$logger = \CreationMedia\CloudWatchLogger\Logger::getInstance();
$logger->setLogName(Config::get('LOG_NAME')); //prepend with file:// for file logging
$logger->setLogLevel($logger::INFO); //Defaults to INFO

$logger::set('STRIP_NAMESPACE', 'Creation\\'); //optionally strip common namespaces
$logger::set('AWS_CREDENTIALS', self::get_aws_creds()); //Guzzle Promise format
$logger::set('SKIP_CREATE', true); //defaults false, skip the creation of streams.

$logger::set('LOG_STREAM', self::get('ENVIRONMENT')); //LOG_STREAM required for CW

//Send some logs'
        \CreationMedia\CloudWatchLogger\Logger::debug('This is simple a debugging message', ['debug' => true]);
        \CreationMedia\CloudWatchLogger\Logger::info('Hello, I\'m a logger, that is some information for you');
        \CreationMedia\CloudWatchLogger\Logger::notice('Take note!', ['key_1'=>'val_1', 'key_2' =>['foo' => 'bar', 'baz' => 'quz'], 'key_3'=>1234]);
        \CreationMedia\CloudWatchLogger\Logger::warning('This IS A WARNING');
        \CreationMedia\CloudWatchLogger\Logger::error('Opps. There is an error!');
        \CreationMedia\CloudWatchLogger\Logger::critical('CRITICAL ERROR');
        \CreationMedia\CloudWatchLogger\Logger::alert('WOOP WOO THIS IS AN ALERT');
        \CreationMedia\CloudWatchLogger\Logger::emergency('Emergency! There\'s an emergency happening');
        //\CreationMedia\CloudWatchLogger\Logger::abort('Just stop it'); //abort level will exit the appliction with the message;

die('test done');
```