<?php
/**
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace notamedia\sentry;

use Sentry\State\Scope;
use yii\helpers\ArrayHelper;
use yii\log\Logger;
use yii\log\Target;

/**
 * SentryTarget records log messages in a Sentry.
 *
 * @see https://sentry.io
 */
class SentryTarget extends Target
{
    /**
     * @var string Sentry client key.
     */
    public $dsn;
    /**
     * @var array Options of the \Sentry.
     */
    public $clientOptions = [];
    /**
     * @var bool Write the context information. The default implementation will dump user information, system variables, etc.
     */
    public $context = true;
    /**
     * @var callable Callback function that can modify extra's array
     */
    public $extraCallback;

    /**
     * @var callable Callback function that can modify user info
     */
    public $runDataCallback;

    /**
     * @inheritdoc
     */
    public function collect($messages, $final)
    {
        \Sentry\init(array_merge(['dsn' => $this->dsn], $this->clientOptions));

        parent::collect($messages, $final);
    }

    /**
     * @inheritdoc
     */
    protected function getContextMessage()
    {
        return '';
    }

    /**
     * @inheritdoc
     */
    public function export()
    {
        foreach ($this->messages as $message) {
            list($text, $level, $category, $timestamp, $traces) = $message;

            $data = [
                'level' => static::getLevelName($level),
                'message' => '',
                'timestamp' => $timestamp,
                'tags' => ['category' => $category],
            ];

            if (is_array($text)) {

                if (isset($text['msg'])) {
                    $data['message'] = $text['msg'];
                    unset($text['msg']);
                }

                if (isset($text['tags'])) {
                    $data['tags'] = ArrayHelper::merge($data['tags'], $text['tags']);
                    unset($text['tags']);
                }

                $data['extra'] = $text;

            } else {
                $data['message'] = $text;
            }

            if ($this->context) {
                $data['extra']['context'] = parent::getContextMessage();
            }

            $data = $this->runExtraCallback($text, $data);

            $data = $this->runDataCallback($text, $data);

            if (!empty($data['extra'])) {
                \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($data): void {
                    foreach ($data['extra'] as $key => $value) {
                        $scope->setExtra((string)$key, $value);
                    }
                });
            }

            if (isset($data['tags'])) {
                \Sentry\configureScope(function (Scope $scope) use ($data): void {
                    foreach ($data['tags'] as $key => $value) {
                        $scope->setTag($key, $value);
                    }
                });
            }

            if ($text instanceof \Throwable || $text instanceof \Exception) {
                \Sentry\captureException($text);
            } else {
                \Sentry\captureMessage($data['message'], $data['level']);
            }
        }
    }

    /**
     * Calls the extra callback if it exists
     *
     * @param $text
     * @param $data
     * @return array
     */
    public function runExtraCallback($text, $data)
    {
        if (is_callable($this->extraCallback)) {
            $data['extra'] = call_user_func($this->extraCallback, $text, isset($data['extra']) ? $data['extra'] : []);
        }

        return $data;
    }

    /**
     * Calls the user callback if it exists
     *
     * @param $text
     * @param $$data
     * @return array
     */
    public function runDataCallback($text, $data)
    {
        if (is_callable($this->runDataCallback)) {
            $data = call_user_func($this->runDataCallback, $text, isset($data) ? $data : []);
        }

        return $data;
    }

    /**
     * Returns the text display of the specified level for the Sentry.
     *
     * @param integer $level The message level, e.g. [[LEVEL_ERROR]], [[LEVEL_WARNING]].
     * @return string
     */
    public static function getLevelName($level)
    {
        static $levels = [
            Logger::LEVEL_ERROR => 'error',
            Logger::LEVEL_WARNING => 'warning',
            Logger::LEVEL_INFO => 'info',
            Logger::LEVEL_TRACE => 'debug',
            Logger::LEVEL_PROFILE_BEGIN => 'debug',
            Logger::LEVEL_PROFILE_END => 'debug',
        ];

        return new \Sentry\Severity(isset($levels[$level]) ? $levels[$level] : 'error');

        return isset($levels[$level]) ? $levels[$level] : 'error';
    }
}
