<?php

namespace wcf\system\exporter;

use wbb\data\board\Board;
use wcf\data\user\UserProfile;
use wcf\system\importer\ImportHandler;
use wcf\util\FileUtil;
use wcf\util\StringUtil;
use wcf\util\UserUtil;

/**
 * Exporter for Xobor
 *
 * @author  Tim Duesterhus
 * @copyright   2001-2019 WoltLab GmbH
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 */
final class XoborExporter extends AbstractExporter
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
        'com.woltlab.wbb.board' => 'Boards',
        'com.woltlab.wbb.thread' => 'Threads',
        'com.woltlab.wbb.post' => 'Posts',
        'com.woltlab.wbb.attachment' => 'PostAttachments',
    ];

    /**
     * @inheritDoc
     */
    protected $limits = [
        'com.woltlab.wcf.user' => 200,
        'com.woltlab.wcf.user.avatar' => 100,
        'com.woltlab.wcf.user.follower' => 100,
    ];

    /**
     * @inheritDoc
     */
    public function getSupportedData()
    {
        return [
            'com.woltlab.wcf.user' => [],
            'com.woltlab.wbb.board' => [
                'com.woltlab.wbb.attachment',
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateDatabaseAccess()
    {
        parent::validateDatabaseAccess();

        $sql = "SELECT  COUNT(*)
                FROM    forum_user";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
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
        }

        // board
        if (\in_array('com.woltlab.wbb.board', $this->selectedData)) {
            $queue[] = 'com.woltlab.wbb.board';
            $queue[] = 'com.woltlab.wbb.thread';
            $queue[] = 'com.woltlab.wbb.post';
            if (\in_array('com.woltlab.wbb.attachment', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.attachment';
            }
        }

        return $queue;
    }

    /**
     * Counts users.
     *
     * @return  integer
     */
    public function countUsers()
    {
        return $this->__getMaxID('forum_user', 'id');
    }

    /**
     * Exports users.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportUsers($offset, $limit)
    {
        // get users
        $sql = "SELECT      *
                FROM        forum_user
                WHERE       id BETWEEN ? AND ?
                ORDER BY    id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'username' => StringUtil::decodeHTML($row['name']),
                'password' => null,
                'email' => $row['mail'],
                'registrationDate' => \strtotime($row['reged']),
                'signature' => self::fixMessage($row['signature_editable']),
                'lastActivityTime' => $row['online'],
            ];

            // get user options
            $options = [
                'birthday' => $row['birthday'],
                'occupation' => $row['occupation'],
                'homepage' => $row['homepage'],
                'icq' => $row['icq'],
                'hobbies' => $row['hobby'],
                'aboutMe' => $row['story_editable'],
                'location' => $row['ploc'],
            ];
            switch ($row['gender']) {
                case 'm':
                    $options['gender'] = UserProfile::GENDER_MALE;
                    break;
                case 'f':
                    $options['gender'] = UserProfile::GENDER_FEMALE;
                    break;
            }

            $additionalData = ['options' => $options];

            // import user
            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user')
                ->import(
                    $row['id'],
                    $data,
                    $additionalData
                );
        }
    }

    /**
     * Counts boards.
     *
     * @return  integer
     */
    public function countBoards()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    forum_foren";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'] ? 1 : 0;
    }

    /**
     * Exports boards.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBoards($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        forum_foren
                ORDER BY    zuid, sort, id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $this->boardCache[$row['zuid']][] = $row;
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
                'parentID' => ($board['zuid'] ?: null),
                'position' => $board['sort'],
                'boardType' => ($board['iscat'] ? Board::TYPE_CATEGORY : Board::TYPE_BOARD),
                'title' => StringUtil::decodeHTML($board['title']),
                'description' => $board['text'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.board')
                ->import($board['id'], $data);

            $this->exportBoardsRecursively($board['id']);
        }
    }

    /**
     * Counts threads.
     *
     * @return  integer
     */
    public function countThreads()
    {
        return $this->__getMaxID("forum_threads", 'id');
    }

    /**
     * Exports threads.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportThreads($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        forum_threads
                WHERE       id BETWEEN ? AND ?
                ORDER BY    id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'boardID' => $row['forum'],
                'topic' => StringUtil::decodeHTML($row['title']),
                'time' => $row['created'],
                'userID' => $row['userid'],
                'username' => StringUtil::decodeHTML($row['name']),
                'views' => $row['hits'],
                'isSticky' => $row['header'] ? 1 : 0,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.thread')
                ->import(
                    $row['id'],
                    $data,
                    []
                );
        }
    }

    /**
     * Counts posts.
     *
     * @return  integer
     */
    public function countPosts()
    {
        return $this->__getMaxID("forum_posts", 'id');
    }

    /**
     * Exports posts.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPosts($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        forum_posts
                WHERE       id BETWEEN ? AND ?
                ORDER BY    id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'threadID' => $row['thread'],
                'userID' => $row['userid'],
                'username' => StringUtil::decodeHTML($row['username']),
                'subject' => StringUtil::decodeHTML($row['title']),
                'message' => $row['text'],
                'time' => \strtotime($row['writetime']),
                'editorID' => null,
                'enableHtml' => 1,
                'isClosed' => 1,
                'ipAddress' => UserUtil::convertIPv4To6($row['useraddr']),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.post')
                ->import($row['id'], $data);
        }
    }

    /**
     * Counts post attachments.
     */
    public function countPostAttachments()
    {
        return $this->__getMaxID('forum_posts', 'id');
    }

    /**
     * Exports post attachments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPostAttachments($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        forum_posts
                WHERE       id BETWEEN ? AND ?
                        AND files <> ?
                        AND files <> ?
                ORDER BY    id";
        $statement = $this->database->prepareUnmanaged($sql);
        $statement->execute([$offset + 1, $offset + $limit, '', \serialize([])]);

        while ($row = $statement->fetchArray()) {
            $files = @\unserialize($row['files']);
            $convert = false;
            if (!$files) {
                $files = @\unserialize(\mb_convert_encoding($row['files'], 'ISO-8859-1', 'UTF-8'));
                $convert = true;
            }
            if (!$files) {
                continue;
            }

            $i = 0;
            foreach ($files as $file) {
                if (!isset($file['filelink'])) {
                    continue;
                }
                $fileLocation = FileUtil::addTrailingSlash($this->fileSystemPath) . $file['filelink'];

                $filename = $file['filename'] ?? $row['filelink'];
                if ($convert) {
                    $filename = \mb_convert_encoding($filename, 'UTF-8', 'ISO-8859-1');
                }

                $data = [
                    'objectID' => $row['id'],
                    'userID' => $row['userid'] ?: null,
                    'filename' => $filename,
                    'downloads' => 0,
                    'uploadTime' => \strtotime($row['writetime']),
                ];

                ImportHandler::getInstance()
                    ->getImporter('com.woltlab.wbb.attachment')
                    ->import(
                        $row['id'] . '-' . $file['filelink'],
                        $data,
                        ['fileLocation' => $fileLocation]
                    );
            }
        }
    }

    private static function fixMessage($string)
    {
        $string = \strtr($string, [
            '[center]' => '[align=center]',
            '[/center]' => '[/align]',
            '[big]' => '[size=18]',
            '[/big]' => '[/size]',
        ]);

        return StringUtil::decodeHTML($string);
    }
}
