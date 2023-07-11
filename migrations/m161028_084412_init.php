<?php

use yii\db\Migration;
use yii\db\Schema;

/**
 * @author Herbert Maschke <thyseus@gmail.com
 */
class m161028_084412_init extends Migration
{
    public function up()
    {
        $tableOptions = '';

        if (Yii::$app->db->driverName == 'mysql') {
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable('{{%message}}', [
            'id' => Schema::TYPE_PK,
            'hash' => Schema::TYPE_STRING . '(32) NOT NULL',
            'from' => Schema::TYPE_INTEGER,
            'to' => Schema::TYPE_INTEGER,
            'status' => Schema::TYPE_INTEGER,
            'title' => Schema::TYPE_STRING . '(255) NOT NULL',
            'message' => Schema::TYPE_TEXT,
            'created_at' => Schema::TYPE_DATETIME . ' NOT NULL',
            'context' => $this->string(4096),
            'params' => $this->json(),
        ], $tableOptions);

        $this->createTable('{{%message_ignorelist}}', [
            'user_id' => Schema::TYPE_INTEGER,
            'blocks_user_id' => Schema::TYPE_INTEGER,
            'created_at' => Schema::TYPE_DATETIME . ' NOT NULL',
        ], $tableOptions);

        $this->createTable('{{%message_allowed_contacts}}', [
            'user_id' => Schema::TYPE_INTEGER,
            'is_allowed_to_write' => Schema::TYPE_INTEGER,
            'created_at' => Schema::TYPE_DATETIME . ' NOT NULL',
            'updated_at' => Schema::TYPE_DATETIME . ' NOT NULL',
        ], $tableOptions);

        $this->addPrimaryKey('message_allowed_contacts-pk', '{{%message_allowed_contacts}}', ['user_id', 'is_allowed_to_write']);

        $this->addPrimaryKey('message_ignorelist-pk', '{{%message_ignorelist}}', ['user_id', 'blocks_user_id']);

        $this->createIndex('message-title', '{{%message}}', 'title');

        $this->createIndex('message-hash', '{{%message}}', 'hash');

        $this->createIndex('message-from', '{{%message}}', 'from');

        $this->createIndex('message-to', '{{%message}}', 'to');

        if (Yii::$app->db->driverName == 'mysql') {
            $this->execute("ALTER TABLE `message` ADD FULLTEXT INDEX `message-message` (message)");
        }

    }

    public function down()
    {
        $this->dropTable('{{%message}}');
        $this->dropTable('{{%message_ignorelist}}');
        $this->dropTable('{{%message_allowed_contacts');
    }
}
