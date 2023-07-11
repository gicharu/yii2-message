<?php

namespace thyseus\message\models;

use app\models\User;
use thyseus\message\jobs\EmailJob;
use thyseus\message\validators\IgnoreListValidator;
use yii;
use yii\behaviors\AttributeBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\Expression;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\HtmlPurifier;

/**
 * Class Message
 *
 * This is the "Message" model class for the yii2-message module.
 * @package thyseus\message\models
 */
class Message extends ActiveRecord
{
    const STATUS_DELETED = -1;
    const STATUS_UNREAD = 0;
    const STATUS_READ = 1;
    const STATUS_ANSWERED = 2;
    const STATUS_DRAFT = 3;
    const STATUS_TEMPLATE = 4;
    const STATUS_SIGNATURE = 5;
    const STATUS_OUT_OF_OFFICE_INACTIVE = 6;
    const STATUS_OUT_OF_OFFICE_ACTIVE = 7;

    const EVENT_BEFORE_MAIL = 'before_mail';
    const EVENT_AFTER_MAIL = 'after_mail';

    public static function tableName()
    {
        return '{{%message}}';
    }

    public static function generateHash(): string
    {
        return md5(uniqid(rand(), true));
    }

    /**
     * @param $from the user id of the sender. Set to null to send a 'system' message.
     * @param $to the user id of the recipient
     * @param $title title of the message (required)
     * @param string $message body of the message (optional)
     * @param null $context set a string or url to define what this message referrs to (optional)
     * @param string $params extra params if the others are not enough
     * @return Message
     */
    public static function compose($from, $to, $title, $message = '', $context = null, $params = null)
    {
        $model = new Message;
        $model->from = $from;
        $model->to = $to;
        $model->title = $title;
        $model->message = $message;
        $model->context = $context;
        $model->status = self::STATUS_UNREAD;
        $model->params = $params;
        $model->save();
        return $model;
    }

    public static function isUserIgnoredBy($victim, $offender)
    {
        foreach (Message::getIgnoredUsers($victim) as $ignored_user) {
            if ($offender == $ignored_user->blocks_user_id) {
                return true;
            }
        }

        return false;
    }

    public static function getIgnoredUsers($for_user)
    {
        return IgnoreListEntry::find()->where(['user_id' => $for_user])->all();
    }

    /**
     * Returns an array of possible recipients for the given user.
     * Applies the ignorelist and applies possible custom logic.
     * @param $for_user
     * @param $to us
     * @return mixed
     */
    public static function getPossibleRecipients($for_user)
    {
        $user = new Yii::$app->controller->module->userModelClass;

        $ignored_users=IgnoreListEntry::find()
            ->select('user_id')
            ->where(['blocks_user_id' => $for_user])
            ->column();

        $allowed_contacts = AllowedContacts::find()
            ->select('is_allowed_to_write')
            ->where(['user_id' => $for_user])
            ->column();

        $users = $user::find();
        $users->where(['!=', 'id', Yii::$app->user->id]);
        $users->andWhere(['not in', 'id', $ignored_users]);

        if ($allowed_contacts) {
            $users->andWhere(['id' => $allowed_contacts]);
        }

        $userIds = $users->select('id')->column();

        if (is_callable(Yii::$app->getModule('message')->recipientsFilterCallback)) {
            $allowedUserIds = call_user_func(Yii::$app->getModule('message')->recipientsFilterCallback, $userIds);
        }

        return User::find()->where(['id' => $allowedUserIds])->limit(200)->all();
    }

    public static function determineUserCaptionAttribute()
    {
        $userModelClass = Yii::$app->getModule('message')->userModelClass;

        if (method_exists($userModelClass, '__toString')) {
            return function ($model) {
                return $model->__toString();
            };
        } else {
            return 'username';
        }
    }

    /**
     * When the recipient has configured an out of office message, we reply to the sender automatically
     */
    public function handleOutOfOfficeMessage()
    {
        $answer = Message::find()->where(
            [
                'from' => $this->to,
                'status' => Message::STATUS_OUT_OF_OFFICE_ACTIVE,
            ]
        )->one();

        if ($answer) {
            Message::compose($this->to, $this->from, $answer->title, $answer->message);
        }
    }

    /**
     * Get all Users that have ever written a message to the given user
     * @param $user_id the user to check for
     * @return array the users that have written him
     */
    public static function userFilter($user_id)
    {
        return ArrayHelper::map(
            Message::find()
                ->where(['to' => $user_id])
                ->select('from')
                ->groupBy('from')
                ->all(),
            'from',
            'sender.username'
        );
    }

    /**
     * @param $user_id
     * @return array|null|Message|ActiveRecord
     */
    public static function getSignature($user_id)
    {
        return Message::find()->where([
            'from' => $user_id,
            'status' => Message::STATUS_SIGNATURE,
        ])->one();
    }

    /**
     * @param $user_id
     * @return array|null|Message|ActiveRecord
     */
    public static function getOutOfOffice($user_id)
    {
        return Message::find()->where([
            'from' => $user_id,
            'status' => [
                Message::STATUS_OUT_OF_OFFICE_INACTIVE,
                Message::STATUS_OUT_OF_OFFICE_ACTIVE,
            ]
        ])->one();
    }

    public function rules()
    {
        return [
            [['title'], 'required'],
            [['title', 'message', 'context', 'params'], 'string'],
            [['title'], 'string', 'max' => 255],
            [['to'], IgnoreListValidator::class],
            [['to'], 'exist',
                'targetClass' => Yii::$app->getModule('message')->userModelClass,
                'targetAttribute' => 'id',
                'message' => Yii::t('app', 'Recipient has not been found'),
            ],
            [['to'], 'required', 'when' => function ($model) {
                return !in_array($model->status, [
                    Message::STATUS_SIGNATURE,
                    Message::STATUS_DRAFT,
                    Message::STATUS_OUT_OF_OFFICE_ACTIVE,
                    Message::STATUS_OUT_OF_OFFICE_INACTIVE,
                ]);
            }],
        ];
    }

    public function behaviors()
    {
        return [
            [
                'class' => AttributeBehavior::class,

                // this is important for auto-saving of drafts in compose view:
                'preserveNonEmptyValues' => true,

                'attributes' => [ActiveRecord::EVENT_BEFORE_INSERT => 'hash'],
                'value' => Message::generateHash(),
            ],
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => null,
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    /**
     * Send E-Mail to recipients if configured.
     * @param $insert
     * @param $changedAttributes
     * @return mixed
     */
    public function afterSave($insert, $changedAttributes)
    {
        if ($insert) {
            $this->handleEmails();
            $this->handleAllowedContacts();
        }

        return parent::afterSave($insert, $changedAttributes);
    }

    protected function handleEmails()
    {
        if (isset($this->recipient->email)) {
            $mailMessages = Yii::$app->getModule('message')->mailMessages;

            if ($mailMessages === true
                || (is_callable($mailMessages) && call_user_func($mailMessages, $this))) {
                $this->sendEmailToRecipient();
            }
        }
    }

    /**
     * Allow the sender to send messages to the recipient in the future.
     * Also allows the recipient to send messages to the sender.
     * @throws yii\db\Exception
     */
    protected function handleAllowedContacts()
    {
        if ($this->from && $this->to) {
            $tablename = AllowedContacts::tableName();
            Yii::$app->db->createCommand()->upsert($tablename, [
                'user_id' => $this->from,
                'is_allowed_to_write' => $this->to,
                'created_at' => date('Y-m-d G:i:s'),
                'updated_at' => date('Y-m-d G:i:s'),
            ], false, [])->execute();

            Yii::$app->db->createCommand()->upsert($tablename, [
                'user_id' => $this->to,
                'is_allowed_to_write' => $this->from,
                'created_at' => date('Y-m-d G:i:s'),
                'updated_at' => date('Y-m-d G:i:s'),
            ], false, [])->execute();
        }
    }

    /**
     * The new message should be send to the recipient via e-mail once.
     * By default, Yii::$app->mailer is used to do so.
     * If you want do enqueue the mail in an queue like yii2-queue or nterms/yii2-mailqueue you
     * can configure this in the module configuration.
     * You can configure your application specific mail views using themeMap.
     *
     * @see https://github.com/yiisoft/yii2-queue
     * @see https://github.com/nterms/yii2-mailqueue
     * @see http://www.yiiframework.com/doc-2.0/yii-base-theme.html
     */
    public function sendEmailToRecipient()
    {
        $this->trigger(Message::EVENT_BEFORE_MAIL);

        if (Yii::$app->getModule('message')->useMailQueue) {
            Yii::$app->queue->push(new EmailJob(['message_id' => $this->id]));
        } else {
            $this->sendEmail();
        }
        $this->trigger(Message::EVENT_AFTER_MAIL);
    }


    public function sendEmail(array $attributes = null)
    {
        $mailer = Yii::$app->{Yii::$app->getModule('message')->mailer};

        $to = $this->recipient->email;
        $from = $this->determineFrom();
        $subject = Html::decode($this->title);
        $model = $this;
        $message = $this->message;

        foreach (['to', 'from', 'subject', 'model', 'message'] as $var) {
            if (isset($attributes[$var])) {
                $$var = $attributes[$var];
            }
        }

        if (!file_exists($mailer->viewPath)) {
            $mailer->viewPath = '@vendor/thyseus/yii2-message/mail/';
        }

        $mailer
            ->compose(['html' => 'message', 'text' => 'text/message'], [
                'model' => $model,
                'content' => $message,
            ])
            ->setTo($to)
            ->setFrom($from)
            ->setSubject($subject)
            ->send();

        return $mailer;
    }

    protected function determineFrom()
    {
        $from = Yii::$app->getModule('message')->from;

        if (is_string($from)) {
            return $from;
        } elseif (is_callable($from)) {
            return call_user_func(Yii::$app->getModule('message')->from, $this);
        }

        return Yii::$app->params['adminEmail'];
    }

    /**
     * Let HTML Purifier run through the user input of the message for security reasons.
     *
     * @param bool $insert
     * @return bool
     */
    public function beforeSave($insert)
    {
        foreach (['title', 'message', 'context'] as $attribute) {
            if (!is_array($this->$attribute)) {
                $this->$attribute = HtmlPurifier::process($this->$attribute);
            }
        }

        return parent::beforeSave($insert);
    }

    public function attributeLabels()
    {
        return [
            'id' => Yii::t('message', '#'),
            'from' => Yii::t('message', 'from'),
            'to' => Yii::t('message', 'to'),
            'title' => Yii::t('message', 'title'),
            'message' => Yii::t('message', 'message'),
            'params' => Yii::t('message', 'params'),
            'created_at' => Yii::t('message', 'created at'),
            'context' => Yii::t('message', 'context'),
        ];
    }

    /** We need to avoid the "Serialization of 'Closure'" is not allowed exception
     * when sending the serialized message object to the queue */
    public function __sleep()
    {
        return [];
    }

    /**
     * Never delete the message physically on the database level.
     * It should always stay in the 'sent' folder of the sender.
     * @return int
     */
    public function delete()
    {
        return $this->updateAttributes(['status' => Message::STATUS_DELETED]);
    }

    public function getRecipientLabel()
    {
        if (!$this->recipient) {
            return Yii::t('message', 'Removed user');
        } else {
            return $this->recipient->username;
        }
    }

    public function getAllowedContacts()
    {
        return $this->hasOne(AllowedContacts::class, ['id' => 'user_id']);
    }

    public function getRecipient()
    {
        return $this->hasOne(Yii::$app->getModule('message')->userModelClass, ['id' => 'to']);
    }

    public function getSender()
    {
        return $this->hasOne(Yii::$app->getModule('message')->userModelClass, ['id' => 'from']);
    }
}
