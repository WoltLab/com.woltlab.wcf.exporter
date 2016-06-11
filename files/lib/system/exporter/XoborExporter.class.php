<?php
namespace wcf\system\exporter;
use wbb\data\board\Board;
use wcf\data\like\Like;
use wcf\data\user\group\UserGroup;
use wcf\data\user\UserProfile;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\StringUtil;
use wcf\util\UserUtil;

/**
 * Exporter for Xobor
 * 
 * @author	Tim Duesterhus
 * @copyright	2001-2016 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
 */
class XoborExporter extends AbstractExporter {
	/**
	 * board cache
	 * @var	array
	 */
	protected $boardCache = array();
	
	/**
	 * @see	\wcf\system\exporter\AbstractExporter::$methods
	 */
	protected $methods = array(
		'com.woltlab.wcf.user' => 'Users',
		'com.woltlab.wbb.board' => 'Boards',
		'com.woltlab.wbb.thread' => 'Threads',
		'com.woltlab.wbb.post' => 'Posts',
	);
	
	/**
	 * @see	\wcf\system\exporter\AbstractExporter::$limits
	 */
	protected $limits = array(
		'com.woltlab.wcf.user' => 200,
		'com.woltlab.wcf.user.avatar' => 100,
		'com.woltlab.wcf.user.follower' => 100
	);
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
			),
			'com.woltlab.wbb.board' => array(
			),
		);
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$sql = "SELECT COUNT(*) FROM forum_user";
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
	 * @see	\wcf\system\exporter\IExporter::getQueue()
	 */
	public function getQueue() {
		$queue = array();
		
		// user
		if (in_array('com.woltlab.wcf.user', $this->selectedData)) {
			$queue[] = 'com.woltlab.wcf.user'; 
		}
		
		// board
		if (in_array('com.woltlab.wbb.board', $this->selectedData)) {
			$queue[] = 'com.woltlab.wbb.board';
			$queue[] = 'com.woltlab.wbb.thread';
			$queue[] = 'com.woltlab.wbb.post';
		}
		
		return $queue;
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		return $this->__getMaxID('forum_user', 'id');
	}
	
	/**
	 * Exports users.
	 */
	public function exportUsers($offset, $limit) {
		// get users
		$sql = "SELECT		*
			FROM		forum_user
			WHERE		id BETWEEN ? AND ?
			ORDER BY	id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => StringUtil::decodeHTML($row['name']),
				'password' => '',
				'email' => $row['mail'],
				'registrationDate' => strtotime($row['reged']),
				'signature' => self::fixMessage($row['signature_editable']),
				'lastActivityTime' => $row['online']
			);
			
			// get user options
			$options = array(
				'birthday' => $row['birthday'],
				'occupation' => $row['occupation'],
				'homepage' => $row['homepage'],
				'icq' => $row['icq'],
				'hobbies' => $row['hobby'],
				'aboutMe' => $row['story_editable'],
				'location' => $row['ploc']
			);
			switch ($row['gender']) {
				case 'm':
					$options['gender'] = UserProfile::GENDER_MALE;
				break;
				case 'f':
					$options['gender'] = UserProfile::GENDER_FEMALE;
				break;
			}
			
			$additionalData = array(
				'options' => $options
			);
			
			// import user
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['id'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	forum_foren";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports boards.
	 */
	public function exportBoards($offset, $limit) {
		$sql = "SELECT		*
			FROM		forum_foren
			ORDER BY	zuid, sort, id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$this->boardCache[$row['zuid']][] = $row;
		}
		
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 */
	protected function exportBoardsRecursively($parentID = 0) {
		if (!isset($this->boardCache[$parentID])) return;
		
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['id'], array(
				'parentID' => ($board['zuid'] ?: null),
				'position' => $board['sort'],
				'boardType' => ($board['iscat'] ? Board::TYPE_CATEGORY : Board::TYPE_BOARD),
				'title' => StringUtil::decodeHTML($board['title']),
				'description' => $board['text']
			));
			
			$this->exportBoardsRecursively($board['id']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		return $this->__getMaxID("forum_threads", 'id');
	}
	
	/**
	 * Exports threads.
	 */
	public function exportThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		forum_threads
			WHERE		id BETWEEN ? AND ?
			ORDER BY	id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			$data = array(
				'boardID' => $row['forum'],
				'topic' => StringUtil::decodeHTML($row['title']),
				'time' => $row['created'],
				'userID' => $row['userid'],
				'username' => StringUtil::decodeHTML($row['name']),
				'views' => $row['hits'],
				'isSticky' => $row['header'] ? 1 : 0
			);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['id'], $data, array());
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		return $this->__getMaxID("forum_posts", 'id');
	}
	
	/**
	 * Exports posts.
	 */
	public function exportPosts($offset, $limit) {
		$sql = "SELECT		*
			FROM		forum_posts
			WHERE		id BETWEEN ? AND ?
			ORDER BY	id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['id'], array(
				'threadID' => $row['thread'],
				'userID' => $row['userid'],
				'username' => StringUtil::decodeHTML($row['username']),
				'subject' => StringUtil::decodeHTML($row['title']),
				'message' => $row['text'],
				'time' => strtotime($row['writetime']),
				'editorID' => null,
				'enableHtml' => 1,
				'isClosed' => 1,
				'ipAddress' => UserUtil::convertIPv4To6($row['useraddr'])
			));
		}
	}
	
	private static function fixMessage($string) {
		$string = strtr($string, array(
			'[center]' => '[align=center]',
			'[/center]' => '[/align]',
			'[big]' => '[size=18]',
			'[/big]' => '[/size]'
		));
		
		return StringUtil::decodeHTML($string);
	}
}
