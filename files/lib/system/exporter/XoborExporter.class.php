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
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) {
			return false;
		}
		
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
				'username' => $row['name'],
				'password' => '',
				'email' => $row['mail'],
				'registrationDate' => strtotime($row['reged']),
				'signature' => self::fixMessage($row['signature_editable']),
				'lastActivityTime' => $row['online']
			);
			
			// import user
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['id'], $data, array());
		}
	}
	
	private static function fixMessage($string) {
		return $string;
	}
}
