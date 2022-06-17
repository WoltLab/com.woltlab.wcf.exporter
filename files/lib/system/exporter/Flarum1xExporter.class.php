<?php

namespace wcf\system\exporter;

use wbb\data\board\Board;
use wcf\data\like\Like;
use wcf\data\object\type\ObjectTypeCache;
use wcf\data\user\group\UserGroup;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\ArrayUtil;
use wcf\util\UserRegistrationUtil;
use wcf\util\UserUtil;

/**
 * Exporter for Flarum 1.x
 *
 * @author  Tim Duesterhus
 * @copyright   2001-2022 WoltLab GmbH
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package WoltLabSuite\Core\System\Exporter
 */
final class Flarum1xExporter extends AbstractExporter
{
    /**
     * @inheritDoc
     */
    protected $methods = [
        'com.woltlab.wcf.user' => 'Users',
        'com.woltlab.wcf.user.group' => 'UserGroups',
        'com.woltlab.wbb.board' => 'Boards',
        'com.woltlab.wbb.thread' => 'Threads',
        'com.woltlab.wbb.post' => 'Posts',
        'com.woltlab.wbb.poll' => 'Polls',
        'com.woltlab.wbb.poll.option' => 'PollOptions',
        'com.woltlab.wbb.poll.option.vote' => 'PollOptionVotes',
        'com.woltlab.wbb.like' => 'Likes',
        'com.woltlab.wcf.label' => 'Labels',
    ];

    /**
     * @inheritDoc
     */
    protected $limits = [
        'com.woltlab.wcf.user' => 200,
    ];

    /**
     * @inheritDoc
     */
    public function getSupportedData()
    {
        return [
            'com.woltlab.wcf.user' => [
                'com.woltlab.wcf.user.group',
            ],
            'com.woltlab.wbb.board' => [
                'com.woltlab.wbb.like',
                'com.woltlab.wbb.poll',
                'com.woltlab.wcf.label',
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
                FROM    migrations";
        $statement = $this->database->prepareStatement($sql);
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
            if (\in_array('com.woltlab.wcf.user.group', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.user.group';
            }

            $queue[] = 'com.woltlab.wcf.user';
        }

        // board
        if (\in_array('com.woltlab.wbb.board', $this->selectedData)) {
            $queue[] = 'com.woltlab.wbb.board';
            if (\in_array('com.woltlab.wcf.label', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.label';
            }
            $queue[] = 'com.woltlab.wbb.thread';
            $queue[] = 'com.woltlab.wbb.post';

            if (\in_array('com.woltlab.wbb.like', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.like';
            }
            if (\in_array('com.woltlab.wbb.poll', $this->selectedData)) {
                $queue[] = 'com.woltlab.wbb.poll';
                $queue[] = 'com.woltlab.wbb.poll.option';
                $queue[] = 'com.woltlab.wbb.poll.option.vote';
            }
        }

        return $queue;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultDatabasePrefix()
    {
        return '';
    }

    /**
     * Counts user groups.
     */
    public function countUserGroups()
    {
        return $this->__getMaxID("`groups`", 'id');
    }

    /**
     * Exports user groups.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportUserGroups($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        `groups`
                WHERE       id BETWEEN ? AND ?
                ORDER BY    id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $userOnlineMarking = '%s';
            if ($row['color']) {
                $userOnlineMarking = '<span style="color: ' . $row['color'] . '">%s</span>';
            }

            $data = [
                'groupName' => $row['name_singular'],
                'groupType' => UserGroup::OTHER,
                'userOnlineMarking' => $userOnlineMarking,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user.group')
                ->import($row['id'], $data);
        }
    }

    /**
     * Counts users.
     */
    public function countUsers()
    {
        return $this->__getMaxID("users", 'id');
    }

    /**
     * Exports users.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportUsers($offset, $limit)
    {
        // prepare password update
        $sql = "UPDATE  wcf1_user
                SET     password = ?
                WHERE   userID = ?";
        $passwordUpdateStatement = WCF::getDB()->prepare($sql);

        // get users
        $sql = "SELECT      users.*,
                            (
                                SELECT  GROUP_CONCAT(group_user.group_id)
                                FROM    group_user
                                WHERE   group_user.user_id = users.id
                            ) AS groupIDs
                FROM        users
                WHERE       users.id BETWEEN ? AND ?
                ORDER BY    users.id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $banned = 0;
            $banExpires = 0;
            if ($row['suspended_until'] !== null) {
                $suspendedUntil = \strtotime($row['suspended_until'] . ' UTC');

                if ($suspendedUntil > \TIME_NOW || $row['suspended_until'] === '2038-01-01 00:00:00') {
                    $banned = 1;
                    $banExpires = $row['suspended_until'] !== '2038-01-01 00:00:00' ? $suspendedUntil : 0;
                }
            }

            $data = [
                'username' => $row['username'],
                'password' => null,
                'email' => $row['email'],
                'registrationDate' => \strtotime($row['joined_at'] . ' UTC'),
                'banned' => $banned,
                'banReason' => $row['suspend_reason'],
                'banExpires' => $banExpires,
                'activationCode' => $row['is_email_confirmed'] == 1 ? 0 : UserRegistrationUtil::getActivationCode(),
                'oldUsername' => '',
                'lastActivityTime' => \strtotime($row['last_seen_at'] . ' UTC'),
            ];

            $additionalData = [
                'groupIDs' => \array_unique(
                    ArrayUtil::toIntegerArray(\explode(',', $row['groupIDs']))
                ),
                'options' => [],
            ];

            // import user
            $newUserID = ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user')
                ->import(
                    $row['id'],
                    $data,
                    $additionalData
                );

            // update password hash
            if ($newUserID) {
                $passwordUpdateStatement->execute([
                    'Bcrypt:' . $row['password'],
                    $newUserID,
                ]);
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
     */
    public function exportBoards($offset, $limit)
    {
        $data = [
            'parentID' => null,
            'position' => 0,
            'boardType' => Board::TYPE_BOARD,
            'title' => 'Imported from Flarum',
        ];

        ImportHandler::getInstance()
            ->getImporter('com.woltlab.wbb.board')
            ->import('XXX', $data);
    }

    /**
     * Counts labels.
     */
    public function countLabels()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    tags";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports labels.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportLabels($offset, $limit)
    {
        $sql = "SELECT  *
                FROM    tags";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        $objectType = ObjectTypeCache::getInstance()
            ->getObjectTypeByName('com.woltlab.wcf.label.objectType', 'com.woltlab.wbb.board');

        while ($row = $statement->fetchArray()) {
            if ($row['position'] === null && $row['parent_id'] === null) {
                // Secondary Tag
                continue;
            }

            // import label group
            $groupData = [
                'groupName' => $row['name'],
            ];

            $additionalData = [
                'objects' => [
                    $objectType->objectTypeID => [
                        ImportHandler::getInstance()->getNewID('com.woltlab.wbb.board', 'XXX'),
                    ],
                ],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.label.group')
                ->import(
                    $row['id'],
                    $groupData,
                    $additionalData
                );

            // import labels
            $labelData = [
                'groupID' => $row['id'],
                'label' => $row['name'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.label')
                ->import(
                    $row['id'],
                    $labelData
                );
        }
    }

    /**
     * Counts threads.
     */
    public function countThreads()
    {
        return $this->__getMaxID("discussions", 'id');
    }

    /**
     * Exports threads.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportThreads($offset, $limit)
    {
        $tags = $this->getTags($offset + 1, $offset + $limit);

        $sql = "SELECT      discussions.*,
                            users.username,
                            (
                                SELECT  GROUP_CONCAT(discussion_tag.tag_id)
                                FROM    discussion_tag
                                WHERE   discussion_tag.discussion_id = discussions.id
                            ) AS tagIDs
                FROM        discussions
                LEFT JOIN   users
                ON          users.id = discussions.user_id
                WHERE       discussions.id BETWEEN ? AND ?
                ORDER BY    discussions.id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'boardID' => 'XXX',
                'topic' => $row['title'],
                'time' => \strtotime($row['created_at'] . ' UTC'),
                'userID' => $row['user_id'],
                'username' => $row['username'] ?: '',
                'views' => 0,
                'isSticky' => $row['is_sticky'] ? 1 : 0,
                'isDisabled' => $row['is_approved'] == 0 ? 1 : 0,
                'isClosed' => $row['is_locked'] ? 1 : 0,
                'isDeleted' => $row['hidden_at'] !== null ? 1 : 0,
                'deleteTime' => $row['hidden_at'] !== null ? \strtotime($row['hidden_at'] . ' UTC') : 0,
            ];

            $additionalData = [];
            if ($row['tagIDs']) {
                $additionalData['labels'] = \array_unique(
                    ArrayUtil::toIntegerArray(\explode(',', $row['tagIDs']))
                );
            }
            if (isset($tags[$row['id']])) {
                $additionalData['tags'] = $tags[$row['id']];
            }

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.thread')
                ->import(
                    $row['id'],
                    $data,
                    $additionalData
                );
        }
    }

    private function getTags(int $start, int $end)
    {
        $condition = new PreparedStatementConditionBuilder();
        $condition->add('tags.position IS NULL');
        $condition->add('tags.parent_id IS NULL');
        $condition->add('(discussion_tag.discussion_id BETWEEN ? AND ?)', [$start, $end]);

        $sql = "SELECT      discussion_tag.discussion_id,
                            tags.name
                FROM        discussion_tag
                INNER JOIN  tags
                ON          discussion_tag.tag_id = tags.id
                {$condition}";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute($condition->getParameters());

        return $statement->fetchMap('discussion_id', 'name', false);
    }

    /**
     * Counts posts.
     */
    public function countPosts()
    {
        return $this->__getMaxID("posts", 'id');
    }

    /**
     * Exports posts.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPosts($offset, $limit)
    {
        $sql = "SELECT      posts.*,
                            users.username,
                            editor.username AS editor,
                            COALESCE(posts.hidden_at, discussions.hidden_at) AS hidden_at
                FROM        posts
                LEFT JOIN   users
                ON          users.id = posts.user_id
                LEFT JOIN   users editor
                ON          editor.id = posts.edited_user_id
                INNER JOIN  discussions
                ON          discussions.id = posts.discussion_id
                WHERE       posts.id BETWEEN ? AND ?
                ORDER BY    posts.id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            switch ($row['type']) {
                case 'comment':
                    // ok
                    break;
                case 'discussionLocked':
                case 'discussionRenamed':
                case 'discussionStickied':
                case 'discussionTagged':
                    continue 2;
                default:
                    continue 2;
            }

            $data = [
                'threadID' => $row['discussion_id'],
                'userID' => $row['user_id'],
                'username' => $row['username'] ?: '',
                'subject' => '',
                'message' => self::fixBBCodes($row['content']),
                'enableHtml' => 1,
                'time' => \strtotime($row['created_at'] . ' UTC'),
                'isDisabled' => $row['is_approved'] == 0 ? 1 : 0,
                'isDeleted' => $row['hidden_at'] !== null ? 1 : 0,
                'deleteTime' => $row['hidden_at'] !== null ? \strtotime($row['hidden_at'] . ' UTC') : 0,
                'editorID' => $row['edited_user_id'] ?: null,
                'editor' => $row['editor'] ?: '',
                'lastEditTime' => $row['edited_at'] !== null ? \strtotime($row['edited_at'] . ' UTC') : 0,
                'ipAddress' => UserUtil::convertIPv4To6($row['ip_address']),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.post')
                ->import($row['id'], $data);
        }
    }

    /**
     * Counts likes.
     */
    public function countLikes()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    post_likes";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports likes.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportLikes($offset, $limit)
    {
        $sql = "SELECT      post_likes.*,
                            posts.user_id AS objectUserID
                FROM        post_likes
                INNER JOIN  posts
                ON          post_likes.post_id = posts.id
                ORDER BY    post_likes.post_id,
                            post_likes.user_id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['post_id'],
                'objectUserID' => $row['objectUserID'],
                'userID' => $row['user_id'],
                'likeValue' => Like::LIKE,
                'time' => \strtotime($row['created_at'] . ' UTC'),
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.like')
                ->import($row['post_id'] . '-' . $row['user_id'], $data);
        }
    }

    /**
     * Counts polls.
     */
    public function countPolls()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    polls";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports polls.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPolls($offset, $limit)
    {
        $sql = "SELECT      posts.id AS objectID,
                            polls.*
                FROM        polls
                INNER JOIN  posts
                ON          polls.discussion_id = posts.discussion_id
                WHERE       (posts.discussion_id, posts.number) IN (
                                SELECT      discussion_id,
                                            MIN(number)
                                FROM        posts
                                WHERE       type = 'comment'
                                GROUP BY    discussion_id
                            )
                ORDER BY    polls.id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'objectID' => $row['objectID'],
                'question' => $row['question'],
                'endTime' => $row['end_date'] !== null ? \strtotime($row['end_date'] . ' UTC') : 0,
                'isChangeable' => 0,
                'isPublic' => $row['public_poll'] ? 1 : 0,
                'maxVotes' => 1,
                'votes' => $row['vote_count'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.poll')
                ->import($row['id'], $data);
        }
    }

    /**
     * Counts poll options.
     */
    public function countPollOptions()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    poll_options";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports poll options.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPollOptions($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        poll_options
                ORDER BY    id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'pollID' => $row['poll_id'],
                'optionValue' => $row['answer'],
                'showOrder' => $row['id'],
                'votes' => $row['vote_count'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.poll.option')
                ->import(
                    $row['id'],
                    $data
                );
        }
    }

    /**
     * Counts poll option votes.
     */
    public function countPollOptionVotes()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    poll_votes";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports poll option votes.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPollOptionVotes($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        poll_votes
                ORDER BY    id";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $data = [
                'pollID' => $row['poll_id'],
                'optionID' => $row['option_id'],
                'userID' => $row['user_id'],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wbb.poll.option.vote')
                ->import($row['id'], $data);
        }
    }

    /**
     * Returns message with BBCodes as used in WCF.
     *
     * @param   string      $message
     * @return  string
     */
    private static function fixBBCodes($message)
    {
        static $parsedown = null;

        if ($parsedown === null) {
            $parsedown = new \Parsedown();
        }

        // Unparser::unparse()
        $message = \html_entity_decode(\strip_tags($message), \ENT_QUOTES, 'UTF-8');

        $message = \preg_replace('/(^|\n)> (```)/', '\\1\\2', $message);

        // fix size bbcode
        $message = \preg_replace_callback('~(?<=\[size=)\d+(?=\])~', static function ($matches) {
            foreach ([8, 10, 12, 14, 18, 24, 36] as $acceptableSize) {
                if ($acceptableSize >= $matches[0]) {
                    return $acceptableSize;
                }
            }

            return 36;
        }, $message);

        $message = \strtr($message, [
            '[center]' => '[align=center]',
            '[/center]' => '[/align]',
        ]);

        $out = $parsedown->text($message);

        $out = \preg_replace(
            '/<pre><code class="language-([a-zA-Z0-9]+)">/',
            '<pre data-file="" data-highlighter="\\1" data-line="1">',
            $out
        );

        $out = \strtr($out, [
            '<blockquote>' => '<woltlab-quote>',
            '</blockquote>' => '</woltlab-quote>',
            '<pre><code>' => '<pre>',
            '</code></pre>' => '</pre>',
            '<code>' => '<kbd>',
            '</code>' => '</kbd>',
        ]);

        $out = \preg_replace('/<\\/p>\\s*<p>/', '</p><p><br></p><p>', $out);

        return $out;
    }
}
