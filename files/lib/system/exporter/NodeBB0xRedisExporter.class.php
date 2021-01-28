<?php

namespace wcf\system\exporter;

use wbb\data\board\Board;
use wcf\data\like\Like;
use wcf\system\exception\SystemException;
use wcf\system\importer\ImportHandler;
use wcf\system\Regex;
use wcf\system\WCF;
use wcf\util\StringUtil;

/**
 * Exporter for NodeBB (Redis).
 *
 * @author  Tim Duesterhus
 * @copyright   2001-2019 WoltLab GmbH
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package WoltLabSuite\Core\System\Exporter
 */
class NodeBB0xRedisExporter extends AbstractExporter
{
    /**
     * board cache
     * @var array
     */
    protected $boardCache = [];

    /**
     * @inheritDoc
     */
    protected $methods = [
        'com.woltlab.wcf.user' => 'Users',
        'com.woltlab.wcf.user.follower' => 'Followers',
        'com.woltlab.wcf.conversation' => 'Conversations',
        'com.woltlab.wcf.conversation.message' => 'ConversationMessages',
        'com.woltlab.wcf.conversation.user' => 'ConversationUsers',
        'com.woltlab.wbb.board' => 'Boards',
        'com.woltlab.wbb.thread' => 'Threads',
        'com.woltlab.wbb.post' => 'Posts',
        'com.woltlab.wbb.like' => 'Likes',
    ];

    /**
     * @inheritDoc
     */
    protected $limits = [
        'com.woltlab.wcf.user' => 100,
    ];

    /**
     * @inheritDoc
     */
    public function init()
    {
        $host = $this->databaseHost;
        $port = 6379;
        if (\preg_match('~^([0-9.]+):([0-9]{1,5})$~', $host, $matches)) {
            // simple check, does not care for valid ip addresses
            $host = $matches[1];
            $port = $matches[2];
        }

        $this->database = new \Redis();
        $this->database->connect($host, $port);

        if ($this->databasePassword) {
            if (!$this->database->auth($this->databasePassword)) {
                throw new SystemException('Could not auth');
            }
        }

        if ($this->databaseName) {
            if (!$this->database->select($this->databaseName)) {
                throw new SystemException('Could not select database');
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getSupportedData()
    {
        return [
            'com.woltlab.wcf.user' => [
                'com.woltlab.wcf.user.follower',
            ],
            'com.woltlab.wcf.conversation' => [
            ],
            'com.woltlab.wbb.board' => [
                'com.woltlab.wbb.like',
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateDatabaseAccess()
    {
        parent::validateDatabaseAccess();

        $result = $this->database->exists('global');
        if (!$result) {
            throw new SystemException("Cannot find 'global' key in database");
        }
    }

    /**
     * @inheritDoc
     */
    public function validateFileAccess()
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getQueue()
    {
        $queue = [];

        // user
        if (\in_array('com.woltlab.wcf.user', $this->selectedData)) {
            $queue[] = 'com.woltlab.wcf.user';

            if (\in_array('com.woltlab.wcf.user.follower', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.follower';
            }

            // conversation
            if (\in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.conversation';
                $queue[] = 'com.woltlab.wcf.conversation.message';
            }
        }

        // board
        if (\in_array('com.woltlab.wbb.board', $this->selectedData)) {
            $queue[] = 'com.woltlab.wbb.board';
            $queue[] = 'com.woltlab.wbb.thread';
            $queue[] = 'com.woltlab.wbb.post';

            if (\in_array('com.woltlab.wbb.like', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.like';
            }
        }

        return $queue;
    }

    /**
     * Counts users.
     */
    public function countUsers()
    {
        return $this->database->zcard('users:joindate');
    }

    /**
     * Exports users.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     * @throws  SystemException
     */
    public function exportUsers($offset, $limit)
    {
        // prepare password update
        $sql = "UPDATE  wcf" . WCF_N . "_user
                SET     password = ?
                WHERE   userID = ?";
        $passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);

        $userIDs = $this->database->zrange('users:joindate', $offset, $offset + $limit);
        if (!$userIDs) {
            throw new SystemException('Could not fetch userIDs');
        }

        foreach ($userIDs as $userID) {
            $row = $this->database->hgetall('user:' . $userID);
            if (!$row) {
                throw new SystemException('Invalid user');
            }

            $data = [
                'username' => $row['username'],
                'password' => null,
                'email' => $row['email'],
                'registrationDate' => \intval($row['joindate'] / 1000),
                'banned' => $row['banned'] ? 1 : 0,
                'banReason' => '',
                'lastActivityTime' => \intval($row['lastonline'] / 1000),
                'signature' => self::convertMarkdown($row['signature']),
            ];

            static $gravatarRegex = null;
            if ($gravatarRegex === null) {
                $gravatarRegex = new Regex('https://(?:secure\.)?gravatar\.com/avatar/([a-f0-9]{32})');
            }

            if ($gravatarRegex->match($row['picture'])) {
                $matches = $gravatarRegex->getMatches();

                if ($matches[1] === \md5($row['email'])) {
                    $data['enableGravatar'] = 1;
                }
            }

            $birthday = \DateTime::createFromFormat('m/d/Y', StringUtil::decodeHTML($row['birthday']));
            // get user options
            $options = [
                'birthday' => $birthday ? $birthday->format('Y-m-d') : '',
                'homepage' => StringUtil::decodeHTML($row['website']),
                'location' => StringUtil::decodeHTML($row['location']),
            ];

            $additionalData = [
                'options' => $options,
            ];

            $newUserID = ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user')
                ->import($row['uid'], $data, $additionalData);

            // update password hash
            if ($newUserID) {
                $password = 'Bcrypt:' . $row['password'];
                $passwordUpdateStatement->execute([$password, $newUserID]);
            }
        }
    }

    /**
     * Counts boards.
     */
    public function countBoards()
    {
        return 1;
    }

    /**
     * Exports boards.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     * @throws  SystemException
     */
    public function exportBoards($offset, $limit)
    {
        $boardIDs = $this->database->zrange('categories:cid', 0, -1);
        if (!$boardIDs) {
            throw new SystemException('Could not fetch boardIDs');
        }

        foreach ($boardIDs as $boardID) {
            $row = $this->database->hgetall('category:' . $boardID);
            if (!$row) {
                throw new SystemException('Invalid board');
            }

            $this->boardCache[$row['parentCid']][] = $row;
        }

        $this->exportBoardsRecursively();
    }

    /**
     * Exports the boards recursively.
     *
     * @param   integer     $parentID
     */
    protected function exportBoardsRecursively($parentID = 0)
    {
        if (!isset($this->boardCache[$parentID])) {
            return;
        }

        foreach ($this->boardCache[$parentID] as $board) {
            $data = [
                'parentID' => $board['parentCid'] ?: null,
                'position' => $board['order'] ?: 0,
                'boardType' => $board['link'] ? Board::TYPE_LINK : Board::TYPE_BOARD,
                'title' => $board['name'],
                'description' => $board['description'],
                'externalURL' => $board['link'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.board')
                ->import($board['cid'], $data);

            $this->exportBoardsRecursively($board['cid']);
        }
    }

    /**
     * Counts threads.
     */
    public function countThreads()
    {
        return $this->database->zcard('topics:tid');
    }

    /**
     * Exports threads.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     * @throws  SystemException
     */
    public function exportThreads($offset, $limit)
    {
        $threadIDs = $this->database->zrange('topics:tid', $offset, $offset + $limit);
        if (!$threadIDs) {
            throw new SystemException('Could not fetch threadIDs');
        }

        foreach ($threadIDs as $threadID) {
            $row = $this->database->hgetall('topic:' . $threadID);
            if (!$row) {
                throw new SystemException('Invalid thread');
            }

            $data = [
                'boardID' => $row['cid'],
                'topic' => $row['title'],
                'time' => \intval($row['timestamp'] / 1000),
                'userID' => $row['uid'],
                'username' => $this->database->hget('user:' . $row['uid'], 'username'),
                'views' => $row['viewcount'],
                'isSticky' => $row['pinned'],
                'isDisabled' => 0,
                'isClosed' => $row['locked'],
                'isDeleted' => $row['deleted'],
                'deleteTime' => TIME_NOW,
            ];

            $additionalData = [
                'tags' => $this->database->smembers('topic:' . $threadID . ':tags') ?: [],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.thread')
                ->import(
                    $row['tid'],
                    $data,
                    $additionalData
                );
        }
    }

    /**
     * Counts posts.
     */
    public function countPosts()
    {
        return $this->database->zcard('posts:pid');
    }

    /**
     * Exports posts.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     * @throws  SystemException
     */
    public function exportPosts($offset, $limit)
    {
        $postIDs = $this->database->zrange('posts:pid', $offset, $offset + $limit);
        if (!$postIDs) {
            throw new SystemException('Could not fetch postIDs');
        }

        foreach ($postIDs as $postID) {
            $row = $this->database->hgetall('post:' . $postID);
            if (!$row) {
                throw new SystemException('Invalid post');
            }

            // TODO: ip address
            $data = [
                'threadID' => $row['tid'],
                'userID' => $row['uid'],
                'username' => $this->database->hget('user:' . $row['uid'], 'username'),
                'subject' => '',
                'message' => self::convertMarkdown($row['content']),
                'time' => \intval($row['timestamp'] / 1000),
                'isDeleted' => $row['deleted'],
                'deleteTime' => TIME_NOW,
                'editorID' => $row['editor'] ?: null,
                'editor' => $this->database->hget('user:' . $row['editor'], 'username'),
                'lastEditTime' => \intval($row['edited'] / 1000),
                'editCount' => $row['edited'] ? 1 : 0,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.post')
                ->import($row['pid'], $data);
        }
    }

    /**
     * Counts likes.
     */
    public function countLikes()
    {
        return $this->database->zcard('users:joindate');
    }

    /**
     * Exports likes.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     * @throws  SystemException
     */
    public function exportLikes($offset, $limit)
    {
        $userIDs = $this->database->zrange('users:joindate', $offset, $offset + $limit);
        if (!$userIDs) {
            throw new SystemException('Could not fetch userIDs');
        }

        foreach ($userIDs as $userID) {
            $likes = $this->database->zrange('uid:' . $userID . ':upvote', 0, -1);

            if ($likes) {
                foreach ($likes as $postID) {
                    $data = [
                        'objectID' => $postID,
                        'objectUserID' => $this->database->hget('post:' . $postID, 'uid') ?: null,
                        'userID' => $userID,
                        'likeValue' => Like::LIKE,
                        'time' => \intval($this->database->zscore('uid:' . $userID . ':upvote', $postID) / 1000),
                    ];

                    ImportHandler::getInstance()
                        ->getImporter('com.woltlab.wbb.like')
                        ->import(0, $data);
                }
            }

            $dislikes = $this->database->zrange('uid:' . $userID . ':downvote', 0, -1);

            if ($dislikes) {
                foreach ($dislikes as $postID) {
                    $data = [
                        'objectID' => $postID,
                        'objectUserID' => $this->database->hget('post:' . $postID, 'uid') ?: null,
                        'userID' => $userID,
                        'likeValue' => Like::DISLIKE,
                        'time' => \intval($this->database->zscore('uid:' . $userID . ':downvote', $postID) / 1000),
                    ];

                    ImportHandler::getInstance()
                        ->getImporter('com.woltlab.wbb.like')
                        ->import(0, $data);
                }
            }
        }
    }

    /**
     * Counts followers.
     */
    public function countFollowers()
    {
        return $this->database->zcard('users:joindate');
    }

    /**
     * Exports followers.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     * @throws  SystemException
     */
    public function exportFollowers($offset, $limit)
    {
        $userIDs = $this->database->zrange('users:joindate', $offset, $offset + $limit);
        if (!$userIDs) {
            throw new SystemException('Could not fetch userIDs');
        }

        foreach ($userIDs as $userID) {
            $followed = $this->database->zrange('following:' . $userID, 0, -1);

            if ($followed) {
                foreach ($followed as $followUserID) {
                    $data = [
                        'userID' => $userID,
                        'followUserID' => $followUserID,
                        'time' => \intval($this->database->zscore('following:' . $userID, $followUserID) / 1000),
                    ];

                    ImportHandler::getInstance()
                        ->getImporter('com.woltlab.wcf.user.follower')
                        ->import(0, $data);
                }
            }
        }
    }

    /**
     * Counts conversations.
     */
    public function countConversations()
    {
        return $this->database->zcard('users:joindate');
    }

    /**
     * Exports conversations.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     * @throws  SystemException
     */
    public function exportConversations($offset, $limit)
    {
        $userIDs = $this->database->zrange('users:joindate', $offset, $offset + $limit);
        if (!$userIDs) {
            throw new SystemException('Could not fetch userIDs');
        }

        foreach ($userIDs as $userID) {
            $chats = $this->database->zrange('uid:' . $userID . ':chats', 0, -1);

            if ($chats) {
                foreach ($chats as $chat) {
                    $conversationID = \min($userID, $chat) . ':to:' . \max($userID, $chat);
                    $firstMessageID = $this->database->zrange('messages:uid:' . $conversationID, 0, 0);
                    if (!$firstMessageID) {
                        throw new SystemException('Could not find first message of conversation');
                    }

                    $firstMessage = $this->database->hgetall('message:' . $firstMessageID[0]);
                    $data = [
                        'subject' => \sprintf(
                            '%s - %s',
                            $this->database->hget('user:' . $userID, 'username'),
                            $this->database->hget('user:' . $chat, 'username')
                        ),
                        'time' => \intval($firstMessage['timestamp'] / 1000),
                        'userID' => $userID,
                        'username' => $this->database->hget('user:' . $firstMessage['fromuid'], 'username'),
                        'isDraft' => 0,
                    ];

                    ImportHandler::getInstance()
                        ->getImporter('com.woltlab.wcf.conversation')
                        ->import($conversationID, $data);

                    // participant a
                    $data = [
                        'conversationID' => $conversationID,
                        'participantID' => $userID,
                        'username' => $this->database->hget('user:' . $userID, 'username'),
                        'hideConversation' => 0,
                        'isInvisible' => 0,
                        'lastVisitTime' => 0,
                    ];

                    ImportHandler::getInstance()
                        ->getImporter('com.woltlab.wcf.conversation.user')
                        ->import(0, $data);

                    // participant b
                    $data = [
                        'conversationID' => $conversationID,
                        'participantID' => $chat,
                        'username' => $this->database->hget('user:' . $chat, 'username'),
                        'hideConversation' => 0,
                        'isInvisible' => 0,
                        'lastVisitTime' => 0,
                    ];

                    ImportHandler::getInstance()
                        ->getImporter('com.woltlab.wcf.conversation.user')
                        ->import(0, $data);
                }
            }
        }
    }

    /**
     * Counts conversation messages.
     */
    public function countConversationMessages()
    {
        return $this->database->hget('global', 'nextMid');
    }

    /**
     * Exports conversation messages.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportConversationMessages($offset, $limit)
    {
        for ($i = 1; $i <= $limit; $i++) {
            $message = $this->database->hgetall('message:' . ($offset + $i));
            if (!$message) {
                continue;
            }
            $conversationID = \sprintf(
                '%s:to:%s',
                \min($message['fromuid'], $message['touid']),
                \max($message['fromuid'], $message['touid'])
            );

            $data = [
                'conversationID' => $conversationID,
                'userID' => $message['fromuid'],
                'username' => $this->database->hget('user:' . $message['fromuid'], 'username'),
                'message' => self::convertMarkdown($message['content']),
                'time' => \intval($message['timestamp'] / 1000),
                'attachments' => 0,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.conversation.message')
                ->import($offset + $i, $data);
        }
    }

    /**
     * Returns message with markdown being convered into BBCodes.
     *
     * @param   string      $message
     * @return  string
     */
    protected static function convertMarkdown($message)
    {
        static $parsedown = null;
        static $codeRegex = null;
        static $imgRegex = null;
        static $urlRegex = null;

        if ($parsedown === null) {
            $parsedown = new \Parsedown();

            $codeRegex = new Regex('<pre><code class="language-([a-z]+)">');
            $imgRegex = new Regex('<img src="([^"]+)"(?: alt="(?:[^"]+)")? />');
            $urlRegex = new Regex('<a href="([^"]+)">');
        }

        $out = $parsedown->text($message);
        $out = $codeRegex->replace($out, '[code=\1]');

        $out = \strtr($out, [
            '<p>' => '',
            '</p>' => '',
            '<br />' => '',

            '<strong>' => '[b]',
            '</strong>' => '[/b]',
            '<em>' => '[i]',
            '</em>' => '[/i]',
            '<ol>' => '[list=1]',
            '</ol>' => '[/list]',
            '<ul>' => '[list]',
            '</ul>' => '[/list]',
            '<li>' => '[*]',
            '</li>' => '',
            '<pre><code>' => '[code]',
            '</code></pre>' => '[/code]',
            '<code>' => '[tt]',
            '</code>' => '[/tt]',
            '<blockquote>' => '[quote]',
            '</blockquote>' => '[/quote]',

            '</a>' => '[/url]',
        ]);

        $out = $imgRegex->replace($out, '[img]\1[/img]');
        $out = $urlRegex->replace($out, '[url=\1]');

        return $out;
    }
}
