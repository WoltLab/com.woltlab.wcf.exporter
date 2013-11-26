<?php
namespace wcf\system\exporter;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\StringUtil;

/**
 * Exporter for WordPress 3.x
 * 
 * @author	Marcel Werk
 * @copyright	2001-2013 WoltLab GmbH
 * @license	WoltLab Burning Board License <http://www.woltlab.com/products/burning_board/license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework (commercial)
 */
class WordPress3xExporter extends AbstractExporter {
	/**
	 * category cache
	 * @var	array
	 */
	protected $categoryCache = array();
	
	/**
	 * @see	\wcf\system\exporter\AbstractExporter::$methods
	 */
	protected $methods = array(
		'com.woltlab.wcf.user' => 'Users',
		'com.woltlab.blog.category' => 'BlogCategories',
		'com.woltlab.blog.entry' => 'BlogEntries',
		'com.woltlab.blog.entry.comment' => 'BlogComments'
	);
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
			),
			'com.woltlab.blog.entry' => array(
				'com.woltlab.blog.category',
				'com.woltlab.blog.entry.comment'
			)
		);
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getQueue()
	 */
	public function getQueue() {
		$queue = array();
	
		// user
		if (in_array('com.woltlab.wcf.user', $this->selectedData)) {
			$queue[] = 'com.woltlab.wcf.user';
		}
		
		// blog
		if (in_array('com.woltlab.blog.entry', $this->selectedData)) {
			if (in_array('com.woltlab.blog.category', $this->selectedData)) $queue[] = 'com.woltlab.blog.category';
			$queue[] = 'com.woltlab.blog.entry';
			if (in_array('com.woltlab.blog.entry.comment', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.comment';
		}
		
		return $queue;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
	
		$sql = "SELECT COUNT(*) FROM ".$this->databasePrefix."posts";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		return true;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getDefaultDatabasePrefix()
	 */
	public function getDefaultDatabasePrefix() {
		return 'wp_';
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."users";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports users.
	 */
	public function exportUsers($offset, $limit) {
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
	
		// get users
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."users
			ORDER BY	ID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
	
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['user_login'],
				'password' => '',
				'email' => $row['user_email'],
				'registrationDate' => @strtotime($row['user_registered'])
			);
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['ID'], $data);
				
			// update password hash
			if ($newUserID) {
				//$passwordUpdateStatement->execute(array($row['user_pass'], $newUserID));
			}
		}
	}
	
	/**
	 * Counts categories.
	 */
	public function countBlogCategories() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."term_taxonomy
			WHERE	taxonomy = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('category'));
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports categories.
	 */
	public function exportBlogCategories($offset, $limit) {
		$sql = "SELECT		term_taxonomy.*, term.name
			FROM		".$this->databasePrefix."term_taxonomy term_taxonomy
			LEFT JOIN	".$this->databasePrefix."terms term
			ON		(term.term_id = term_taxonomy.term_id)
			WHERE		term_taxonomy.taxonomy = ?
			ORDER BY	term_taxonomy.parent, term_taxonomy.term_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('category'));
		while ($row = $statement->fetchArray()) {
			$this->categoryCache[$row['parent']][] = $row;
		}
	
		$this->exportBlogCategoriesRecursively();
	}
	
	/**
	 * Exports the categories recursively.
	 */
	protected function exportBlogCategoriesRecursively($parentID = 0) {
		if (!isset($this->categoryCache[$parentID])) return;
	
		foreach ($this->categoryCache[$parentID] as $category) {
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.category')->import($category['term_id'], array(
				'title' => StringUtil::decodeHTML($category['name']),
				'parentCategoryID' => $category['parent'],
				'showOrder' => 0
			));
				
			$this->exportBlogCategoriesRecursively($category['term_id']);
		}
	}
	
	/**
	 * Counts blog entries.
	 */
	public function countBlogEntries() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."posts
			WHERE	post_type = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('post'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports blog entries.
	 */
	public function exportBlogEntries($offset, $limit) {
		// get entry ids
		$entryIDs = array();
		$sql = "SELECT		ID
			FROM		".$this->databasePrefix."posts
			WHERE		post_type = ?
			ORDER BY	ID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('post'));
		while ($row = $statement->fetchArray()) {
			$entryIDs[] = $row['ID'];
		}
	
		// get tags
		$tags = array();
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('term_taxonomy.term_taxonomy_id = term_relationships.term_taxonomy_id');
		$conditionBuilder->add('term_relationships.object_id IN (?)', array($entryIDs));
		$conditionBuilder->add('term_taxonomy.taxonomy = ?', array('post_tag'));
		$conditionBuilder->add('term.term_id IS NOT NULL');
		$sql = "SELECT		term.name, term_relationships.object_id
			FROM		".$this->databasePrefix."term_relationships term_relationships,
					".$this->databasePrefix."term_taxonomy term_taxonomy
			LEFT JOIN	".$this->databasePrefix."terms term
			ON		(term.term_id = term_taxonomy.term_id)
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			if (!isset($tags[$row['object_id']])) $tags[$row['object_id']] = array();
			$tags[$row['object_id']][] = $row['name'];
		}
		
		// get categories
		$categories = array();
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('term_taxonomy.term_taxonomy_id = term_relationships.term_taxonomy_id');
		$conditionBuilder->add('term_relationships.object_id IN (?)', array($entryIDs));
		$conditionBuilder->add('term_taxonomy.taxonomy = ?', array('category'));
		$sql = "SELECT		term_taxonomy.term_id, term_relationships.object_id
			FROM		".$this->databasePrefix."term_relationships term_relationships,
					".$this->databasePrefix."term_taxonomy term_taxonomy
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			if (!isset($categories[$row['object_id']])) $categories[$row['object_id']] = array();
			$categories[$row['object_id']][] = $row['term_id'];
		}
	
		// get entries
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('post.ID IN (?)', array($entryIDs));
	
		$sql = "SELECT		post.*, user.user_login
			FROM		".$this->databasePrefix."posts post
			LEFT JOIN	".$this->databasePrefix."users user
			ON		(user.ID = post.post_author)
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$additionalData = array();
			if (isset($tags[$row['ID']])) $additionalData['tags'] = $tags[$row['ID']];
			if (isset($categories[$row['ID']])) $additionalData['categories'] = $categories[$row['ID']];
				
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry')->import($row['ID'], array(
				'userID' => ($row['post_author'] ?: null),
				'username' => ($row['user_login'] ?: ''),
				'subject' => $row['post_title'],
				'message' => self::fixMessage($row['post_content']),
				'time' => @strtotime($row['post_date_gmt']),
				'comments' => $row['comment_count'],
				'enableSmilies' => 0,
				'enableHtml' => 1,
				'enableBBCodes' => 0,
				'isPublished' => ($row['post_status'] == 'publish' ? 1 : 0)
			), $additionalData);
		}
	}
	
	/**
	 * Counts blog comments.
	 */
	public function countBlogComments() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."comments";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports blog comments.
	 */
	public function exportBlogComments($offset, $limit) {
		$sql = "SELECT	comment_ID, comment_parent
			FROM	".$this->databasePrefix."comments
			WHERE	comment_ID = ?";
		$parentCommentStatement = $this->database->prepareStatement($sql, $limit, $offset);
		
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."comments
			ORDER BY	comment_parent, comment_ID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			if (!$row['comment_parent']) {
				ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.comment')->import($row['comment_ID'], array(
					'objectID' => $row['comment_post_ID'],
					'userID' => ($row['user_id'] ?: null),
					'username' => $row['comment_author'],
					'message' => StringUtil::decodeHTML($row['comment_content']),
					'time' => @strtotime($row['comment_date_gmt'])
				));
			}
			else {
				$parentID = $row['comment_parent'];
				
				do {
					$parentCommentStatement->execute(array($parentID));
					$row2 = $parentCommentStatement->fetchArray();
					
					if (!$row2['comment_parent']) {
						ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.comment.response')->import($row['comment_ID'], array(
							'commentID' => $row2['comment_ID'],
							'userID' => ($row['user_id'] ?: null),
							'username' => $row['comment_author'],
							'message' => StringUtil::decodeHTML($row['comment_content']),
							'time' => @strtotime($row['comment_date_gmt'])
						));
						break;
					}
					$parentID = $row2['comment_parent'];
				}
				while (true);
			}
		}
	}
	
	private static function fixMessage($string) {
		$string = str_replace("\n", "<br />\n", StringUtil::unifyNewlines($string));
		
		return $string;
	}
}