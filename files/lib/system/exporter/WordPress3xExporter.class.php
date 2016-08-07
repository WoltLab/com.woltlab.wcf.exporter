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
 * @copyright	2001-2015 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
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
		'com.woltlab.blog.entry.comment' => 'BlogComments',
		'com.woltlab.blog.entry.attachment' => 'BlogAttachments'
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
				'com.woltlab.blog.entry.comment',
				'com.woltlab.blog.entry.attachment'
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
			if (in_array('com.woltlab.blog.entry.attachment', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.attachment';
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
		if (in_array('com.woltlab.blog.entry.attachment', $this->selectedData)) {
			if (empty($this->fileSystemPath) || (!@file_exists($this->fileSystemPath . 'wp-trackback.php'))) return false;
		}
		
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
		return $this->__getMaxID($this->databasePrefix."users", 'ID');
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
			WHERE		ID BETWEEN ? AND ?
			ORDER BY	ID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
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
				$passwordUpdateStatement->execute(array('phpass:'.$row['user_pass'].':', $newUserID));
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
					AND post_status IN (?, ?, ?, ?, ?, ?)
			ORDER BY	ID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('post', 'publish', 'pending', 'draft', 'future', 'private', 'trash'));
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
			
			$time = @strtotime($row['post_date_gmt']);
			if (!$time) $time = @strtotime($row['post_date']);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry')->import($row['ID'], array(
				'userID' => ($row['post_author'] ?: null),
				'username' => ($row['user_login'] ?: ''),
				'subject' => $row['post_title'],
				'message' => self::fixMessage($row['post_content']),
				'time' => $time,
				'comments' => $row['comment_count'],
				'enableSmilies' => 1,
				'enableHtml' => 0,
				'enableBBCodes' => 1,
				'isPublished' => ($row['post_status'] == 'publish' ? 1 : 0),
				'isDeleted' => ($row['post_status'] == 'trash' ? 1 : 0)
			), $additionalData);
		}
	}
	
	/**
	 * Counts blog comments.
	 */
	public function countBlogComments() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."comments
			WHERE	comment_approved = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(1));
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
			WHERE	comment_approved = ?
			ORDER BY	comment_parent, comment_ID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(1));
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
	

	/**
	 * Counts blog attachments.
	 */
	public function countBlogAttachments() {
		$sql = "SELECT		COUNT(*) AS count
			FROM		".$this->databasePrefix."posts
			WHERE		post_type = ?
					AND post_parent IN (
						SELECT	ID
						FROM	".$this->databasePrefix."posts	
						WHERE	post_type = ?
							AND post_status IN (?, ?, ?, ?, ?, ?)
					)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('attachment', 'post', 'publish', 'pending', 'draft', 'future', 'private', 'trash'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports blog attachments.
	 */
	public function exportBlogAttachments($offset, $limit) {
		$sql = "SELECT		posts.*, postmeta.*
			FROM		".$this->databasePrefix."posts posts
			LEFT JOIN	".$this->databasePrefix."postmeta postmeta
			ON		(postmeta.post_id = posts.ID AND postmeta.meta_key = ?)
			WHERE		post_type = ?
					AND post_parent IN (
						SELECT	ID
						FROM	".$this->databasePrefix."posts
						WHERE	post_type = ?
							AND post_status IN (?, ?, ?, ?, ?, ?)
					)
			ORDER BY	ID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('_wp_attached_file', 'attachment', 'post', 'publish', 'pending', 'draft', 'future', 'private', 'trash'));
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath.'wp-content/uploads/'.$row['meta_value'];
			
			$isImage = 0;
			if ($row['post_mime_type'] == 'image/jpeg' || $row['post_mime_type'] == 'image/png' || $row['post_mime_type'] == 'image/gif') $isImage = 1;
			
			$time = @strtotime($row['post_date_gmt']);
			if (!$time) $time = @strtotime($row['post_date']);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.attachment')->import($row['meta_id'], array(
				'objectID' => $row['post_parent'],
				'userID' => ($row['post_author'] ?: null),
				'filename' => basename($fileLocation),
				'filesize' => filesize($fileLocation),
				'fileType' => $row['post_mime_type'],
				'isImage' => $isImage,
				'downloads' => 0,
				'lastDownloadTime' => 0,
				'uploadTime' => $time,
				'showOrder' => 0
			), array('fileLocation' => $fileLocation));
		}
	}
	
	private static function fixMessage($string) {
		// we wanna get rid of f*ckin html in articles, so we have to remove a lot of crap
		// btw. I hate this stuff and I'm pretty sure a lot of ppl. could do this much better
		// really guys, I have no idea what I'm doing
		// however, lets go...
		
		// <hr /> tags
		$string = str_ireplace('<hr />', '', $string);
		
		// new lines
		$string = str_replace("\n\n\n", "\n\n", StringUtil::unifyNewlines($string));
		$string = str_ireplace('<br />', "\n", $string);
		$string = str_ireplace('<br>', "\n", $string);
		
		// quotes
		$string = preg_replace('~<blockquote[^>]*>(.*?)</blockquote>~is', '[quote]\\1[/quote]', $string);
		
		// read more
		$string = str_ireplace('<!--more-->', '', $string);
		
		// deleted
		$string = preg_replace('~<del[^>]*>(.*?)</del>~is', '[s]\\1[/s]', $string);
		
		// list
		$string = str_ireplace('<ul style="list-style-type: circle;">', '[list]', $string);
		$string = str_ireplace('<ul>', '[list]', $string);
		$string = str_ireplace('</ul>', '[/list]', $string);
		$string = str_ireplace('<li>', '[*]', $string);
		$string = str_ireplace('</li>', '', $string);
		$string = str_ireplace('<ol>', '[list=1]', $string);
		$string = str_ireplace('</ol>', '[/list]', $string);
		
		// code
		$string = preg_replace('~<code[^>]*>(.*?)</code>~is', '[code]\\1[/code]', $string);
		$string = preg_replace('~<pre[^>]*>(.*?)</pre>~is', '[code]\\1[/code]', $string);
		
		// non-breaking space
		$string = str_ireplace('&nbsp;', '', $string);
		
		// bold
		$string = preg_replace('~(<strong)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = str_ireplace('<strong>', '[b]', $string);
		$string = str_ireplace('</strong>', '[/b]', $string);
		$string = str_ireplace('<b>', '[b]', $string);
		$string = str_ireplace('</b>', '[/b]', $string);
		
		// italic
		$string = preg_replace('~(<i)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<em)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = str_ireplace('<em>', '[i]', $string);
		$string = str_ireplace('</em>', '[/i]', $string);
		$string = str_ireplace('<i>', '[i]', $string);
		$string = str_ireplace('</i>', '[/i]', $string);
		
		// color
		$string = preg_replace('~<span style="color: (.*?);?">(.*?)</span>~', '[color=\\1]\\2[/color]', $string);
		
		// underline
		$string = preg_replace('~<span style="text-decoration: underline;?">(.*?)</span>~', '[u]\\1[/u]', $string);
		$string = str_ireplace('<u>', '[u]', $string);
		$string = str_ireplace('</u>', '[/u]', $string);
				
		// font size
		$string = preg_replace('~<span style="font-size:(\d+)px;">(.*?)</span>~is', '[size=\\1]\\2[/size]', $string);
		
		// font color
		$string = preg_replace('~<span style="color:(.*?);?">(.*?)</span>~is', '[color=\\1]\\2[/color]', $string);
		
		// sup and sub
		$string = preg_replace('~<sup>(.*?)</sup>~is', '[sup]\\1[/sup]', $string);
		$string = preg_replace('~<sub>(.*?)</sub>~is', '[sub]\\1[/sub]', $string);
		
		// align
		$string = preg_replace('~<p style="text-align:(left|center|right);">(.*?)</p>~is', '[align=\\1]\\2[/align]', $string);
		$string = preg_replace('~<p style="text-align: (left|center|right);">(.*?)</p>~is', '[align=\\1]\\2[/align]', $string);
		$string = preg_replace('~(<p)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		
		// remove attributes
		$string = preg_replace('~(<table)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<article)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<tr)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<td)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<th)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<div)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<span)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<h1)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<h2)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<h3)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<h4)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<h5)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		$string = preg_replace('~(<h6)\b[^>]*?(?=\h*\/?>)~', '\1', $string);
		
		// get the rest of them
		$string = preg_replace('#\s(wmode|allowfullscreen|rel|data-hovercard|data-ft|target|id|title|alt|colspan|feature|scrolling|scope|width|height|bgcolor|cellspacing|frameborder|tabindex|valign|border|cellpadding)="[^"]+"#', '', $string);
		
		// heading elements
		$string = str_ireplace('<h1>', '[size=24][b]', $string);
		$string = str_ireplace('</h1>', '[/size][/b]', $string);
		$string = str_ireplace('<h2>', '[size=18][b]', $string);
		$string = str_ireplace('</h2>', '[/size][/b]', $string);
		$string = str_ireplace('<h3>', '[size=14][b]', $string);
		$string = str_ireplace('</h3>', '[/size][/b]', $string);
		$string = str_ireplace('<h4>', '[size=12][b]', $string);
		$string = str_ireplace('</h4>', '[/size][/b]', $string);
		$string = str_ireplace('<h5>', '[size=10][b]', $string);
		$string = str_ireplace('</h5>', '[/size][/b]', $string);
		$string = str_ireplace('<h6>', '[size=10][b]', $string);
		$string = str_ireplace('</h6>', '[/size][/b]', $string);
		
		// remove obsolete code
		$string = str_ireplace('<p>', '', $string);
		$string = str_ireplace('</p>', '', $string);
		$string = str_ireplace('<span>', '', $string);
		$string = str_ireplace('</span>', '', $string);
		$string = str_ireplace('<center>', '', $string);
		$string = str_ireplace('</center>', '', $string);
		$string = str_ireplace('<div>', '', $string);
		$string = str_ireplace('</div>', '', $string);
		$string = str_ireplace('<article>', '', $string);
		$string = str_ireplace('</article>', '', $string);
		$string = str_ireplace('<aside>', '', $string);
		$string = str_ireplace('</aside>', '', $string);
		$string = str_ireplace('<tbody>', '', $string);
		$string = str_ireplace('</tbody>', '', $string);
		$string = str_ireplace('<thead>', '', $string);
		$string = str_ireplace('</thead>', '', $string);
		$string = str_ireplace('<section>', '', $string);
		$string = str_ireplace('</section>', '', $string);
		
		// tables
		$string = str_ireplace('<table>', '[table]', $string);
		$string = str_ireplace('</table>', '[/table]', $string);
		$string = str_ireplace('<tr>', '[tr]', $string);
		$string = str_ireplace('</tr>', '[/tr]', $string);
		$string = str_ireplace('<td>', '[td]', $string);
		$string = str_ireplace('</td>', '[/td]', $string);
		$string = str_ireplace('<th>', '[td]', $string);
		$string = str_ireplace('</th>', '[/td]', $string);
		
		// media
		$string = str_ireplace('[embed]', '[media]', $string);
		$string = str_ireplace('[/embed]', '[/media]', $string);
		
		// youtube
		$string = preg_replace('~<iframe src="http(s)?://(m|www\.)?youtube\.com/embed/([a-zA-Z0-9_\-]+)"></iframe>~is', '[media]https://youtu.be/\\3[/media]', $string);
		$string = preg_replace('~<iframe src="http(s)?://(m|www\.)?youtube\.com/embed/([a-zA-Z0-9_\-]+)" allowfullscreen=""></iframe>~is', '[media]https://youtu.be/\\3[/media]', $string);
		$string = preg_replace('#http(s)?://(m|www\.)?youtube\.com/embed/([a-zA-Z0-9_\-]+)#i', '[media]https://youtu.be/\\3[/media]', $string);
		$string = preg_replace('#http(s)?://(m|www\.)?youtube\.com/watch\?v=([a-zA-Z0-9_\-]+)#i', '[media]https://youtu.be/\\3[/media]', $string);
		
		// dailymotion
		$string = preg_replace('#http(s)?://(www\.)?dailymotion\.com/embed/video/([a-zA-Z0-9_\-]+)#i', '[media]https://www.dailymotion.com/video/\\3[/media]', $string);
		$string = preg_replace('#http(s)?://(www\.)?dailymotion\.com/video/([a-zA-Z0-9_\-]+)#i', '[media]https://www.dailymotion.com/video/\\3[/media]', $string);
		
		// vimeo
		$string = preg_replace('~<iframe src="http(s)?://(www\.)?vimeo\.com/([\d]+)"></iframe>~is', '[media]https://vimeo.com/\\3[/media]', $string);
		$string = preg_replace('#http(s)?://(www\.)?vimeo\.com/([\d]+)#i', '[media]https://vimeo.com/\\3[/media]', $string);
		
		// veoh
		$string = preg_replace('#http(s)?://(www\.)?veoh\.com/watch/v([a-zA-Z0-9_\-]+)#i', '[media]http://www.veoh.com/watch/v\\3[/media]', $string);
		
		// souncloud 
		$string = preg_replace('#http(s)?://(m|www\.)?soundcloud\.com/([a-z0-9_\-]+/[a-z0-9_\-]+)#i', '[media]https://soundcloud.com/\\3[/media]', $string);
		
		// caption (to do: remove caption image description)
		$string = preg_replace('#\[caption[^\]]*\](.*?)\[/caption\]#i', '\\1', $string);
		
		// img
		$string = preg_replace('/(class=["\'][^\'"]*)size-(full|medium|large|thumbnail)\s?/', '\\1', $string );
		$string = preg_replace('/(class=["\'][^\'"]*)wp-image-([0-9]+)\s?/', '\\1', $string );
		$string = preg_replace('/(class=["\'][^\'"]*)align(none|center)\s?/', '\\1', $string );
		$string = preg_replace('~<img class="alignleft" src="(.*?)" />~', '[img=\'\\1\',left][/img]', $string);
		$string = preg_replace('~<img class="alignright" src="(.*?)" />~', '[img=\'\\1\',right][/img]', $string);
		$string = preg_replace('~<img src="(.*?)" class="alignleft" />~', '[img=\'\\1\',left][/img]', $string);
		$string = preg_replace('~<img src="(.*?)" class="alignright" />~', '[img=\'\\1\',right][/img]', $string);
		$string = preg_replace('~<img[^>]+src=["\']([^"\']+)["\'][^>]*/?>~is', '[img]\\1[/img]', $string);
		
		// mails
		$string = preg_replace('~<a.*?href=(?:"|\')mailto:([^"]*)(?:"|\')>(.*?)</a>~is', '[email=\'\\1\']\\2[/email]', $string);
		
		// urls
		$string = preg_replace('~<a.*?href=(?:"|\')([^"]*)(?:"|\')>(.*?)</a>~is', '[url=\'\\1\']\\2[/url]', $string);
		
		// still to do: soundcloud and youtube playlist, clipfish, github, ins, caption image description and all that embled object-stuff
		// there's an issue (maybe just in the one article i saw) with some wikipedia links, other works fine?!?
		// did I already said I hate this stuff?
		// goodbye!
		
		return $string;
	}
}
