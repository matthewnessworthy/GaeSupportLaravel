<?php

namespace A1comms\GaeSupportLaravel\Integration\ErrorReporting;

use Google\Cloud\Logging\LoggingClient;
use Google\Cloud\Logging\PsrLogger;

/**
 * Static methods for bootstrapping Stackdriver Error Reporting.
 */
if (is_gae_std()) {
    class Report
    {
        public static function init(PsrLogger $psrLogger = null)
        {

        }

        /**
         * @param mixed $ex \Throwable (PHP 7) or \Exception (PHP 5)
         */
        public static function exceptionHandler($ex, $status_code = 500)
        {
            $message = sprintf('PHP Notice: %s', (string)$ex);
            self::sendReport([
                'message' => $message,
                'context' => [
                    'reportLocation' => [
                        'filePath' => $ex->getFile(),
                        'lineNumber' => $ex->getLine(),
                        'functionName' =>
                            self::getFunctionNameForReport($ex->getTrace()),
                    ],
                    'httpRequest' => [
                        'method'                => empty($_SERVER['REQUEST_METHOD']) ?: $_SERVER['REQUEST_METHOD'],
                        'url'                   => (empty($_SERVER['HTTP_HOST']) ? '' :  $_SERVER['HTTP_HOST']). (empty($_SERVER['REQUEST_URI']) ? '' : $_SERVER['REQUEST_URI']),
                        'userAgent'             => empty($_SERVER['HTTP_USER_AGENT']) ? '' : $_SERVER['HTTP_USER_AGENT'],
                        'referrer'              => empty($_SERVER['HTTP_USER_AGENT']) ? '' : $_SERVER['HTTP_USER_AGENT'],
                        "responseStatusCode"    => $status_code,
                        "remoteIp"              => empty($_SERVER['REMOTE_ADDR']) ? '' : $_SERVER['REMOTE_ADDR'],
                    ],
                ],
                'serviceContext' => [
                    'service' => gae_service(),
                    'version' => gae_version(),
                ]
            ]);
        }

        /**
         * Format the function name from a stack trace. This could be a global
         * function (function_name), a class function (Class->function), or a static
         * function (Class::function).
         *
         * @param array $trace The stack trace returned from Exception::getTrace()
         */
        private static function getFunctionNameForReport(array $trace = null)
        {
            if (null === $trace) {
                return '<unknown function>';
            }
            if (empty($trace[0]['function'])) {
                return '<none>';
            }
            $functionName = [$trace[0]['function']];
            if (isset($trace[0]['type'])) {
                $functionName[] = $trace[0]['type'];
            }
            if (isset($trace[0]['class'])) {
                $functionName[] = $trace[0]['class'];
            }
            return implode('', array_reverse($functionName));
        }

        public static function sendReport($data)
        {
            if (!is_gae_production()) {
                return;
            }

            $clientParams = [
                'verify'=>'/etc/ca-certificates.crt',
                'Content-Type' => 'application/json',
            ];
            $client = new \GuzzleHttp\Client($clientParams);
            $authData = self::getToken();
            $accessToken = $authData['access_token'];
            $path = sprintf('https://clouderrorreporting.googleapis.com/v1beta1/projects/%s/events:report', gae_project());
            $content = [
                'connect_timeout' => 2,
                'timeout' => 2,
                'json' => $data,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => "Bearer {$accessToken}",
                ],
            ];
            $response = $client->post(
                $path,
                $content
            );
        }

        public static function getToken()
        {
            $g = new \Google\Auth\Credentials\AppIdentityCredentials('https://www.googleapis.com/auth/stackdriver-integration');
            return $g->fetchAuthToken();
        }
    }
} else {
    class Report
    {
        const DEFAULT_LOGNAME = 'exception';

        /** @var PsrLogger */
        public static $psrLogger;

        /**
         * Register hooks for error reporting.
         *
         * @param PsrLogger $psrLogger
         * @return void
         * @codeCoverageIgnore
         */
        public static function init(PsrLogger $psrLogger = null)
        {
            $options = [];

            self::$psrLogger = $psrLogger ?: (new LoggingClient())
                ->psrLogger(self::DEFAULT_LOGNAME, $options);

            register_shutdown_function([self::class, 'shutdownHandler']);
            set_exception_handler([self::class, 'exceptionHandler']);
            set_error_handler([self::class, 'errorHandler']);
        }

        /**
         * Return a string prefix for the given error level.
         *
         * @param int $level
         * @return string A string prefix for reporting the error.
         */
        public static function getErrorPrefix($level)
        {
            switch ($level) {
                case E_PARSE:
                    $prefix = 'PHP Parse error';
                    break;
                case E_ERROR:
                case E_CORE_ERROR:
                case E_COMPILE_ERROR:
                    $prefix = 'PHP Fatal error';
                    break;
                case E_USER_ERROR:
                case E_RECOVERABLE_ERROR:
                    $prefix = 'PHP error';
                    break;
                case E_WARNING:
                case E_CORE_WARNING:
                case E_COMPILE_WARNING:
                case E_USER_WARNING:
                    $prefix = 'PHP Warning';
                    break;
                case E_NOTICE:
                case E_USER_NOTICE:
                    $prefix = 'PHP Notice';
                    break;
                case E_STRICT:
                    $prefix = 'PHP Debug';
                    break;
                default:
                    $prefix = 'PHP Notice';
            }
            return $prefix;
        }

        /**
         * Return an error level string for the given PHP error level.
         *
         * @param int $level
         * @return string An error level string.
         */
        public static function getErrorLevelString($level)
        {
            switch ($level) {
                case E_PARSE:
                    return 'CRITICAL';
                case E_ERROR:
                case E_CORE_ERROR:
                case E_COMPILE_ERROR:
                case E_USER_ERROR:
                case E_RECOVERABLE_ERROR:
                    return 'ERROR';
                case E_WARNING:
                case E_CORE_WARNING:
                case E_COMPILE_WARNING:
                case E_USER_WARNING:
                    return 'WARNING';
                case E_NOTICE:
                case E_USER_NOTICE:
                    return 'NOTICE';
                case E_STRICT:
                    return 'DEBUG';
                default:
                    return 'NOTICE';
            }
        }

        /**
         * @param mixed $ex \Throwable (PHP 7) or \Exception (PHP 5)
         */
        public static function exceptionHandler($ex, $status_code = 500)
        {
            $message = sprintf('PHP Notice: %s', (string)$ex);
            if (self::$psrLogger) {
                $service = self::$psrLogger->getMetadataProvider()->serviceId();
                $version = self::$psrLogger->getMetadataProvider()->versionId();
                self::$psrLogger->error($message, [
                    'context' => [
                        'reportLocation' => [
                            'filePath' => $ex->getFile(),
                            'lineNumber' => $ex->getLine(),
                            'functionName' =>
                                self::getFunctionNameForReport($ex->getTrace()),
                        ],
                        'httpRequest' => [
                            'method'                => empty($_SERVER['REQUEST_METHOD']) ?: $_SERVER['REQUEST_METHOD'],
                            'url'                   => (empty($_SERVER['HTTP_HOST']) ? '' :  $_SERVER['HTTP_HOST']). (empty($_SERVER['REQUEST_URI']) ? '' : $_SERVER['REQUEST_URI']),
                            'userAgent'             => empty($_SERVER['HTTP_USER_AGENT']) ? '' : $_SERVER['HTTP_USER_AGENT'],
                            'referrer'              => empty($_SERVER['HTTP_USER_AGENT']) ? '' : $_SERVER['HTTP_USER_AGENT'],
                            "responseStatusCode"    => $status_code,
                            "remoteIp"              => empty($_SERVER['REMOTE_ADDR']) ? '' : $_SERVER['REMOTE_ADDR'],
                        ],
                    ],
                    'serviceContext' => [
                        'service' => $service,
                        'version' => $version,
                    ]
                ]);
            } else {
                fwrite(STDERR, $message . PHP_EOL);
            }
        }

        /**
         * @param int $level The error level.
         * @param string $message The error message.
         * @param string $file The filename that the error was raised in.
         * @param int $line The line number that the error was raised at.
         */
        public static function errorHandler($level, $message, $file, $line)
        {
            if (!($level & error_reporting())) {
                return true;
            }
            $message =  sprintf(
                '%s: %s in %s on line %d',
                self::getErrorPrefix($level),
                $message,
                $file,
                $line
            );
            if (!self::$psrLogger) {
                return false;
            }
            $service = self::$psrLogger->getMetadataProvider()->serviceId();
            $version = self::$psrLogger->getMetadataProvider()->versionId();
            $context = [
                'context' => [
                    'reportLocation' => [
                        'filePath' => $file,
                        'lineNumber' => $line,
                        'functionName' =>
                            self::getFunctionNameForReport(),
                    ],
                    'httpRequest' => [
                        'method'                => empty($_SERVER['REQUEST_METHOD']) ?: $_SERVER['REQUEST_METHOD'],
                        'url'                   => (empty($_SERVER['HTTP_HOST']) ? '' :  $_SERVER['HTTP_HOST']). (empty($_SERVER['REQUEST_URI']) ? '' : $_SERVER['REQUEST_URI']),
                        'userAgent'             => empty($_SERVER['HTTP_USER_AGENT']) ? '' : $_SERVER['HTTP_USER_AGENT'],
                        'referrer'              => empty($_SERVER['HTTP_USER_AGENT']) ? '' : $_SERVER['HTTP_USER_AGENT'],
                        "responseStatusCode"    => $status_code,
                        "remoteIp"              => empty($_SERVER['REMOTE_ADDR']) ? '' : $_SERVER['REMOTE_ADDR'],
                    ],
                ],
                'serviceContext' => [
                    'service' => $service,
                    'version' => $version
                ]
            ];
            self::$psrLogger->log(
                self::getErrorLevelString($level),
                $message,
                $context
            );
        }

        /**
         * Called at exit, to check there's a fatal error and report the error if
         * any.
         */
        public static function shutdownHandler()
        {
            if ($err = error_get_last()) {
                switch ($err['type']) {
                    case E_ERROR:
                    case E_PARSE:
                    case E_COMPILE_ERROR:
                    case E_CORE_ERROR:
                        $service = self::$psrLogger
                            ->getMetadataProvider()
                            ->serviceId();
                        $version = self::$psrLogger
                            ->getMetadataProvider()
                            ->versionId();
                        $message = sprintf(
                            '%s: %s in %s on line %d',
                            self::getErrorPrefix($err['type']),
                            $err['message'],
                            $err['file'],
                            $err['line']
                        );
                        $context = [
                            'context' => [
                                'reportLocation' => [
                                    'filePath' => $err['file'],
                                    'lineNumber' => $err['line'],
                                    'functionName' =>
                                        self::getFunctionNameForReport(),
                                ]
                            ],
                            'serviceContext' => [
                                'service' => $service,
                                'version' => $version
                            ]
                        ];
                        if (self::$psrLogger) {
                            self::$psrLogger->log(
                                self::getErrorLevelString($err['type']),
                                $message,
                                $context
                            );
                        }
                        break;
                }
            }
        }

        /**
         * Format the function name from a stack trace. This could be a global
         * function (function_name), a class function (Class->function), or a static
         * function (Class::function).
         *
         * @param array $trace The stack trace returned from Exception::getTrace()
         */
        private static function getFunctionNameForReport(array $trace = null)
        {
            if (null === $trace) {
                return '<unknown function>';
            }
            if (empty($trace[0]['function'])) {
                return '<none>';
            }
            $functionName = [$trace[0]['function']];
            if (isset($trace[0]['type'])) {
                $functionName[] = $trace[0]['type'];
            }
            if (isset($trace[0]['class'])) {
                $functionName[] = $trace[0]['class'];
            }
            return implode('', array_reverse($functionName));
        }
    }
}