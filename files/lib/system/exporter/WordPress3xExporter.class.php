<?php

namespace wcf\system\exporter;

use wcf\data\article\Article;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\html\input\HtmlInputProcessor;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\StringUtil;

/**
 * Exporter for WordPress 3.x
 *
 * @author  Marcel Werk
 * @copyright   2001-2019 WoltLab GmbH
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 */
final class WordPress3xExporter extends AbstractExporter
{
    /**
     * category cache
     * @var array
     */
    protected $categoryCache = [];

    /**
     * @inheritDoc
     */
    protected $limits = [
        'com.woltlab.wcf.media' => 50,
    ];

    /**
     * @inheritDoc
     */
    protected $methods = [
        'com.woltlab.wcf.user' => 'Users',
        'com.woltlab.wcf.article.category' => 'BlogCategories',
        'com.woltlab.wcf.article' => 'BlogEntries',
        'com.woltlab.wcf.article.comment' => 'BlogComments',
        'com.woltlab.wcf.media' => 'Media',
        'com.woltlab.wcf.page' => 'Pages',
    ];

    /**
     * @inheritDoc
     */
    public function getSupportedData()
    {
        return [
            'com.woltlab.wcf.user' => [],
            'com.woltlab.wcf.article' => [
                'com.woltlab.wcf.article.category',
                'com.woltlab.wcf.article.comment',
            ],
            'com.woltlab.wcf.media' => [],
            'com.woltlab.wcf.page' => [],
        ];
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

        // media
        if (\in_array('com.woltlab.wcf.media', $this->selectedData)) {
            $queue[] = 'com.woltlab.wcf.media';
        }

        // article
        if (\in_array('com.woltlab.wcf.article', $this->selectedData)) {
            if (\in_array('com.woltlab.wcf.article.category', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.article.category';
            }
            $queue[] = 'com.woltlab.wcf.article';
            if (\in_array('com.woltlab.wcf.article.comment', $this->selectedData)) {
                $queue[] = 'com.woltlab.wcf.article.comment';
            }
        }

        // pages
        if (\in_array('com.woltlab.wcf.page', $this->selectedData)) {
            $queue[] = 'com.woltlab.wcf.page';
        }

        return $queue;
    }

    /**
     * @inheritDoc
     */
    public function validateDatabaseAccess()
    {
        parent::validateDatabaseAccess();

        $sql = "SELECT  COUNT(*)
                FROM    " . $this->databasePrefix . "posts";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute();
    }

    /**
     * @inheritDoc
     */
    public function validateFileAccess()
    {
        if (\in_array('com.woltlab.wcf.media', $this->selectedData)) {
            if (empty($this->fileSystemPath) || (!@\file_exists($this->fileSystemPath . 'wp-trackback.php'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultDatabasePrefix()
    {
        return 'wp_';
    }

    /**
     * Counts users.
     */
    public function countUsers()
    {
        return $this->__getMaxID($this->databasePrefix . "users", 'ID');
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
        $sql = "UPDATE  wcf" . WCF_N . "_user
                SET     password = ?
                WHERE   userID = ?";
        $passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);

        // get users
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "users
                WHERE       ID BETWEEN ? AND ?
                ORDER BY    ID";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([$offset + 1, $offset + $limit]);
        while ($row = $statement->fetchArray()) {
            $data = [
                'username' => $row['user_login'],
                'password' => null,
                'email' => $row['user_email'],
                'registrationDate' => @\strtotime($row['user_registered']),
            ];

            // import user
            $newUserID = ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.user')
                ->import($row['ID'], $data);

            // update password hash
            if ($newUserID) {
                $passwordUpdateStatement->execute([
                    'phpass:' . $row['user_pass'] . ':',
                    $newUserID,
                ]);
            }
        }
    }

    /**
     * Counts categories.
     */
    public function countBlogCategories()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "term_taxonomy
                WHERE   taxonomy = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['category']);
        $row = $statement->fetchArray();

        return $row['count'] ? 1 : 0;
    }

    /**
     * Exports categories.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogCategories($offset, $limit)
    {
        $sql = "SELECT      term_taxonomy.*, term.name
                FROM        " . $this->databasePrefix . "term_taxonomy term_taxonomy
                LEFT JOIN   " . $this->databasePrefix . "terms term
                ON          term.term_id = term_taxonomy.term_id
                WHERE       term_taxonomy.taxonomy = ?
                ORDER BY    term_taxonomy.parent, term_taxonomy.term_id";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['category']);
        while ($row = $statement->fetchArray()) {
            $this->categoryCache[$row['parent']][] = $row;
        }

        $this->exportBlogCategoriesRecursively();
    }

    /**
     * Exports the categories recursively.
     *
     * @param   integer     $parentID
     */
    protected function exportBlogCategoriesRecursively($parentID = 0)
    {
        if (!isset($this->categoryCache[$parentID])) {
            return;
        }

        foreach ($this->categoryCache[$parentID] as $category) {
            $data = [
                'title' => StringUtil::decodeHTML($category['name']),
                'parentCategoryID' => $category['parent'],
                'showOrder' => 0,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.article.category')
                ->import($category['term_id'], $data);

            $this->exportBlogCategoriesRecursively($category['term_id']);
        }
    }

    /**
     * Counts blog entries.
     */
    public function countBlogEntries()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "posts
                WHERE   post_type = ?
                    AND post_status IN (?, ?, ?, ?, ?)";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute(['post', 'publish', 'pending', 'draft', 'future', 'private']);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports blog entries.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogEntries($offset, $limit)
    {
        // get entry ids
        $entryIDs = [];
        $sql = "SELECT      ID
                FROM        " . $this->databasePrefix . "posts
                WHERE       post_type = ?
                        AND post_status IN (?, ?, ?, ?, ?)
                ORDER BY    ID";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute(['post', 'publish', 'pending', 'draft', 'future', 'private']);
        while ($row = $statement->fetchArray()) {
            $entryIDs[] = $row['ID'];
        }

        // get tags
        $tags = [];
        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('term_taxonomy.term_taxonomy_id = term_relationships.term_taxonomy_id');
        $conditionBuilder->add('term_relationships.object_id IN (?)', [$entryIDs]);
        $conditionBuilder->add('term_taxonomy.taxonomy = ?', ['post_tag']);
        $conditionBuilder->add('term.term_id IS NOT NULL');
        $sql = "SELECT      term.name, term_relationships.object_id
                FROM        " . $this->databasePrefix . "term_relationships term_relationships,
                            " . $this->databasePrefix . "term_taxonomy term_taxonomy
                LEFT JOIN   " . $this->databasePrefix . "terms term
                ON          term.term_id = term_taxonomy.term_id
                " . $conditionBuilder;
        $statement = $this->database->prepareStatement($sql);
        $statement->execute($conditionBuilder->getParameters());
        while ($row = $statement->fetchArray()) {
            if (!isset($tags[$row['object_id']])) {
                $tags[$row['object_id']] = [];
            }
            $tags[$row['object_id']][] = $row['name'];
        }

        // get categories
        $categories = [];
        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('term_taxonomy.term_taxonomy_id = term_relationships.term_taxonomy_id');
        $conditionBuilder->add('term_relationships.object_id IN (?)', [$entryIDs]);
        $conditionBuilder->add('term_taxonomy.taxonomy = ?', ['category']);
        $sql = "SELECT  term_taxonomy.term_id, term_relationships.object_id
                FROM    " . $this->databasePrefix . "term_relationships term_relationships,
                        " . $this->databasePrefix . "term_taxonomy term_taxonomy
                " . $conditionBuilder;
        $statement = $this->database->prepareStatement($sql);
        $statement->execute($conditionBuilder->getParameters());
        while ($row = $statement->fetchArray()) {
            if (!isset($categories[$row['object_id']])) {
                $categories[$row['object_id']] = [];
            }
            $categories[$row['object_id']][] = $row['term_id'];
        }

        // get entries
        $conditionBuilder = new PreparedStatementConditionBuilder();
        $conditionBuilder->add('post.ID IN (?)', [$entryIDs]);

        $sql = "SELECT      post.*, user.user_login,
                            (
                                SELECT  meta_value
                                FROM    " . $this->databasePrefix . "postmeta
                                WHERE   post_id = post.ID
                                    AND meta_key = '_thumbnail_id'
                                LIMIT   1
                            ) AS imageID
                FROM        " . $this->databasePrefix . "posts post
                LEFT JOIN   " . $this->databasePrefix . "users user
                ON          user.ID = post.post_author
                " . $conditionBuilder;
        $statement = $this->database->prepareStatement($sql);
        $statement->execute($conditionBuilder->getParameters());
        while ($row = $statement->fetchArray()) {
            $time = @\strtotime($row['post_date_gmt']);
            if (!$time) {
                $time = @\strtotime($row['post_date']);
            }
            if ($time < 0) {
                $time = TIME_NOW;
            }

            $data = [
                'userID' => $row['post_author'] ?: null,
                'username' => $row['user_login'] ?: '',
                'time' => $time,
                'categoryID' => (isset($categories[$row['ID']]) ? \reset($categories[$row['ID']]) : null),
                'publicationStatus' => $row['post_status'] == 'publish' ? Article::PUBLISHED : Article::UNPUBLISHED,
            ];

            $additionalData = [
                'contents' => [
                    0 => [
                        'title' => $row['post_title'],
                        'content' => self::fixMessage($row['post_content']),
                        'tags' => ($tags[$row['ID']] ?? []),
                        'imageID' => ($row['imageID'] ?: null),
                    ],
                ],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.article')
                ->import($row['ID'], $data, $additionalData);
        }
    }

    /**
     * Counts blog comments.
     */
    public function countBlogComments()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "comments
                WHERE   comment_approved = ?";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([1]);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports blog comments.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportBlogComments($offset, $limit)
    {
        $sql = "SELECT  comment_ID, comment_parent
                FROM    " . $this->databasePrefix . "comments
                WHERE   comment_ID = ?";
        $parentCommentStatement = $this->database->prepareStatement($sql, $limit, $offset);

        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "comments
                WHERE       comment_approved = ?
                ORDER BY    comment_parent, comment_ID";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([1]);
        while ($row = $statement->fetchArray()) {
            if (!$row['comment_parent']) {
                $data = [
                    'userID' => $row['user_id'] ?: null,
                    'username' => $row['comment_author'],
                    'message' => StringUtil::decodeHTML($row['comment_content']),
                    'time' => @\strtotime($row['comment_date_gmt']),
                ];

                ImportHandler::getInstance()
                    ->getImporter('com.woltlab.wcf.article.comment')
                    ->import(
                        $row['comment_ID'],
                        $data,
                        ['articleID' => $row['comment_post_ID']]
                    );
            } else {
                $parentID = $row['comment_parent'];

                do {
                    $parentCommentStatement->execute([$parentID]);
                    $row2 = $parentCommentStatement->fetchArray();

                    if (!$row2['comment_parent']) {
                        $data = [
                            'commentID' => $row2['comment_ID'],
                            'userID' => $row['user_id'] ?: null,
                            'username' => $row['comment_author'],
                            'message' => StringUtil::decodeHTML($row['comment_content']),
                            'time' => @\strtotime($row['comment_date_gmt']),
                        ];

                        ImportHandler::getInstance()
                            ->getImporter('com.woltlab.wcf.article.comment.response')
                            ->import($row['comment_ID'], $data);
                        break;
                    }
                    $parentID = $row2['comment_parent'];
                } while (true);
            }
        }
    }

    /**
     * Counts media.
     */
    public function countMedia()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "posts
                WHERE   post_type = ?
                    AND post_parent IN (
                            SELECT  ID
                            FROM    " . $this->databasePrefix . "posts
                            WHERE   post_type IN (?, ?)
                                AND post_status IN (?, ?, ?, ?, ?, ?)
                        )";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([
            'attachment',

            'page',
            'post',

            'publish',
            'pending',
            'draft',
            'future',
            'private',
            'trash',
        ]);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports media.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportMedia($offset, $limit)
    {
        $sql = "SELECT      posts.*, postmeta.*
                FROM        " . $this->databasePrefix . "posts posts
                LEFT JOIN   " . $this->databasePrefix . "postmeta postmeta
                ON          postmeta.post_id = posts.ID
                        AND postmeta.meta_key = ?
                WHERE       post_type = ?
                        AND post_parent IN (
                                SELECT  ID
                                FROM    " . $this->databasePrefix . "posts
                                WHERE   post_type IN (?, ?)
                                    AND post_status IN (?, ?, ?, ?, ?, ?)
                            )
                ORDER BY    ID";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([
            '_wp_attached_file',

            'attachment',

            'page',
            'post',

            'publish',
            'pending',
            'draft',
            'future',
            'private',
            'trash',
        ]);
        while ($row = $statement->fetchArray()) {
            $fileLocation = $this->fileSystemPath . 'wp-content/uploads/' . $row['meta_value'];
            if (!\file_exists($fileLocation)) {
                continue;
            }

            $isImage = $width = $height = 0;
            if (
                $row['post_mime_type'] == 'image/jpeg'
                || $row['post_mime_type'] == 'image/png'
                || $row['post_mime_type'] == 'image/gif'
            ) {
                $isImage = 1;
            }
            if ($isImage) {
                $imageData = @\getimagesize($fileLocation);
                if ($imageData === false) {
                    continue;
                }
                $width = $imageData[0];
                $height = $imageData[1];
            }

            $time = @\strtotime($row['post_date_gmt']);
            if (!$time) {
                $time = @\strtotime($row['post_date']);
            }

            $data = [
                'filename' => \basename($fileLocation),
                'filesize' => \filesize($fileLocation),
                'fileType' => $row['post_mime_type'],
                'fileHash' => \md5_file($fileLocation),
                'uploadTime' => $time,
                'userID' => $row['post_author'] ?: null,
                'isImage' => $isImage,
                'width' => $width,
                'height' => $height,
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.media')
                ->import(
                    $row['ID'],
                    $data,
                    ['fileLocation' => $fileLocation, 'contents' => []]
                );
        }
    }

    /**
     * Counts pages.
     */
    public function countPages()
    {
        $sql = "SELECT  COUNT(*) AS count
                FROM    " . $this->databasePrefix . "posts
                WHERE   post_type = ?
                    AND post_status IN (?, ?, ?, ?, ?)";
        $statement = $this->database->prepareStatement($sql);
        $statement->execute([
            'page',

            'publish',
            'pending',
            'draft',
            'future',
            'private',
        ]);
        $row = $statement->fetchArray();

        return $row['count'];
    }

    /**
     * Exports blog entries.
     *
     * @param   integer     $offset
     * @param   integer     $limit
     */
    public function exportPages($offset, $limit)
    {
        $sql = "SELECT      *
                FROM        " . $this->databasePrefix . "posts
                WHERE       post_type = ?
                        AND post_status IN (?, ?, ?, ?, ?)
                ORDER BY    ID";
        $statement = $this->database->prepareStatement($sql, $limit, $offset);
        $statement->execute([
            'page',

            'publish',
            'pending',
            'draft',
            'future',
            'private',
        ]);
        while ($row = $statement->fetchArray()) {
            $time = @\strtotime($row['post_date_gmt']);
            if (!$time) {
                $time = @\strtotime($row['post_date']);
            }
            if ($time < 0) {
                $time = TIME_NOW;
            }

            $data = [
                'name' => $row['post_title'],
                'pageType' => 'text',
                'isDisabled' => ($row['post_status'] == 'publish' ? 1 : 0),
                'lastUpdateTime' => $time,
            ];

            $additionalData = [
                'contents' => [
                    0 => [
                        'title' => $row['post_title'],
                        'content' => self::fixMessage($row['post_content']),
                        'customURL' => $row['post_name'],
                    ],
                ],
            ];

            ImportHandler::getInstance()
                ->getImporter('com.woltlab.wcf.page')
                ->import(
                    $row['ID'],
                    $data,
                    $additionalData
                );
        }
    }

    /**
     * Returns message with fixed syntax as used in WCF.
     *
     * @param   string      $string
     * @return  string
     */
    private static function fixMessage($string)
    {
        // replace media
        $string = \preg_replace_callback(
            '~<img class="([^"]*wp-image-(\d+)[^"]*)".*?>~is',
            static function ($matches) {
                $mediaID = ImportHandler::getInstance()->getNewID('com.woltlab.wcf.media', $matches[2]);
                if (!$mediaID) {
                    return $matches[0];
                }

                $alignment = 'none';
                if (\strpos($matches[1], 'alignleft') !== false) {
                    $alignment = 'left';
                } elseif (\strpos($matches[1], 'alignright') !== false) {
                    $alignment = 'right';
                }

                $size = 'original';
                if (\strpos($matches[1], 'size-thumbnail') !== false) {
                    $size = 'small';
                } elseif (\strpos($matches[1], 'size-medium') !== false) {
                    $size = 'medium';
                } elseif (\strpos($matches[1], 'size-large') !== false) {
                    $size = 'large';
                }

                $data = [$mediaID, $size, $alignment];

                return \sprintf(
                    '<woltlab-metacode data-name="wsm" data-attributes="%s"></woltlab-metacode>',
                    \base64_encode(\json_encode($data))
                );
            },
            $string
        );

        $processor = new HtmlInputProcessor();
        $processor->process($string, 'com.woltlab.wcf.article.content');
        $string = $processor->getHtml();

        $string = \str_replace("</p>", "</p><p><br></p>", $string);
        /* Remove trailing linebreak in quotes */
        $string = \preg_replace("~<p><br></p>(?=\\s*</blockquote>)~", '', $string);

        $string = \str_replace("<blockquote>", "<woltlab-quote>", $string);
        $string = \str_replace("</blockquote>", "</woltlab-quote>", $string);

        return $string;
    }
}
