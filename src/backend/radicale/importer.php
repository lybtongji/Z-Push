<?php
/***********************************************
* File      :   backend/radicale/importer.php
* Project   :   Z-Push
* Descr     :   Importer class for the radicale backend.
*
* Created   :   13.03.2026
************************************************/

class ImportChangesRadicale implements IImportChanges {
    private $backend;
    private $folderid;
    private $icc;

    /**
     * Constructor of the ImportChangesRadicale class
     *
     * @param object $backend
     * @param string $folderid
     * @param object $importer
     *
     * @access public
     */
    public function __construct(&$backend, $folderid = false, $icc = false) {
        $this->backend = $backend;
        $this->folderid = $folderid;
        $this->icc = &$icc;
    }

    /**
     * Loads objects which are expected to be exported with the state
     * Before importing/saving the actual message from the mobile, a conflict detection should be done
     *
     * @param ContentParameters         $contentparameters         class of objects
     * @param string                    $state
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function LoadConflicts($contentparameters, $state) {
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesRadicale->LoadConflicts() icc not configured");
            return false;
        }
        $this->icc->LoadConflicts($contentparameters, $state);
    }

    /**
     * Imports a single message
     *
     * @param string        $id
     * @param SyncObject    $message
     *
     * @access public
     * @return boolean/string               failure / id of message
     */
    public function ImportMessageChange($id, $message) {
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesRadicale->ImportMessageChange() icc not configured");
            return false;
        }
        return $this->icc->ImportMessageChange($id, $message);
    }

    /**
     * Imports a deletion. This may conflict if the local object has been modified.
     *
     * @param string        $id
     * @param boolean       $asSoftDelete   (opt) if true, the deletion is exported as "SoftDelete", else as "Remove" - default: false
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageDeletion($id, $asSoftDelete = false) {
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesRadicale->ImportMessageDeletion() icc not configured");
            return false;
        }
        return $this->icc->ImportMessageDeletion($id, $asSoftDelete);
    }

    /**
     * Imports a change in 'read' flag
     * This can never conflict
     *
     * @param string        $id
     * @param int           $flags
     * @param array         $categories
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageReadFlag($id, $flags, $categories = array()) {
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesRadicale->ImportMessageReadFlag() icc not configured");
            return false;
        }
        return $this->icc->ImportMessageReadFlag($id, $flags, $categories);
    }

    /**
     * Imports a move of a message. This occurs when a user moves an item to another folder
     *
     * @param string        $id
     * @param string        $newfolder
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageMove($id, $newfolder) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesRadicale->ImportMessageMove('%s', '%s')", $id, $newfolder));
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesRadicale->ImportMessageMove icc not configured");
            return false;
        }
        if($this->backend->GetBackendId($this->folderid) != $this->backend->GetBackendId($newfolder)){
            ZLog::Write(LOGLEVEL_WARN, "ImportChangesRadicale->ImportMessageMove() cannot move message between two backends");
            return false;
        }
        return $this->icc->ImportMessageMove($id, $this->backend->GetBackendFolder($newfolder));
    }


    /**----------------------------------------------------------------------------------------------------------
     * Methods to import hierarchy
     */

    /**
     * Imports a change on a folder
     *
     * @param object        $folder         SyncFolder
     *
     * @access public
     * @return boolean/SyncObject           status/object with the ath least the serverid of the folder set
     */
    public function ImportFolderChange($folder) {
        $id = $folder->serverid;
        $parent = $folder->parentid;
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesRadicale->ImportFolderChange() id: '%s', parent: '%s'", $id, $parent));
        if($parent == '0') {
            if($id) {
                $backendid = $this->backend->GetBackendId($id);
            }
            else {
                ZLog::Write(LOGLEVEL_WARN, "ImportChangesRadicale->ImportFolderChange() no rootcreatefolderbackend");
                // $backendid = $this->backend->config['rootcreatefolderbackend'];
                return false;
            }
        }
        else {
            $backendid = $this->backend->GetBackendId($parent);
            $folder->parentid = $this->backend->GetBackendFolder($parent);
        }

        if(!empty($this->backend->config['backends'][$backendid]['subfolder']) && $id == $backendid.$this->backend->config['delimiter'].'0') {
            ZLog::Write(LOGLEVEL_WARN, "ImportChangesRadicale->ImportFolderChange() cannot change static folder");
            return false;
        }

        if($id != false) {
            if($backendid != $this->backend->GetBackendId($id)) {
                ZLog::Write(LOGLEVEL_WARN, "ImportChangesRadicale->ImportFolderChange() cannot move folder between two backends");
                return false;
            }
            $id = $this->backend->GetBackendFolder($id);
        }
        $this->icc = $this->backend->getBackend($backendid.$this->backend->config['delimiter'].$id)->GetImporter();
        $resFolder = $this->icc->ImportFolderChange($folder);
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportChangesRadicale->ImportFolderChange() success');
        $folder->serverid = $backendid . $this->backend->config['delimiter'] . $resFolder->serverid;
        // TODO Check if move folder is supported ($parent is different). This is tricky, because you could tell e.g. a CardDAV folder to be moved to the trash of the IMAP backend on the mobile.
        return $folder;
    }

    /**
     * Imports a folder deletion
     *
     * @param SyncFolder    $folder         at least "serverid" needs to be set
     *
     * @access public
     * @return boolean/int  success/SYNC_FOLDERHIERARCHY_STATUS
     */
    public function ImportFolderDeletion($folder) {
        $id = $folder->serverid;
        $parent = isset($folder->parentid) ? $folder->parentid : false;
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesRadicale->ImportFolderDeletion('%s', '%s')", $id, $parent));
        $backendid = $this->backend->GetBackendId($id);
        if(!empty($this->backend->config['backends'][$backendid]['subfolder']) && $id == $backendid.$this->backend->config['delimiter'].'0') {
            ZLog::Write(LOGLEVEL_WARN, "ImportChangesRadicale->ImportFolderDeletion() cannot change static folder");
            return false; //we can not change a static subfolder
        }

        $backend = $this->backend->GetBackend($id);
        $id = $this->backend->GetBackendFolder($id);

        if($parent != '0')
            $parent = $this->backend->GetBackendFolder($parent);

        $this->icc = $backend->GetImporter();
        $folder->serverid = $id;
        $folder->parentid = $parent;
        $res = $this->icc->ImportFolderDeletion($folder);
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportChangesRadicale->ImportFolderDeletion() success');
        return $res;
    }


    /**
     * Initializes the state and flags
     *
     * @param string        $state
     * @param int           $flags
     *
     * @access public
     * @return boolean      status flag
     */
    public function Config($state, $flags = 0) {
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportChangesRadicale->Config(...)');
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesRadicale->Config() icc not configured");
            return false;
        }
        $this->icc->Config($state, $flags);
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportChangesRadicale->Config() success');
    }


    /**
     * Configures additional parameters used for content synchronization
     *
     * @param ContentParameters         $contentparameters
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function ConfigContentParameters($contentparameters) {
        ZLog::Write(LOGLEVEL_DEBUG, "ImportChangesRadicale->ConfigContentParameters()");
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesRadicale->ConfigContentParameters() icc not configured");
            return false;
        }
        $this->icc->ConfigContentParameters($contentparameters);
        ZLog::Write(LOGLEVEL_DEBUG, "ImportChangesRadicale->ConfigContentParameters() success");
    }

    /**
     * Reads and returns the current state
     *
     * @access public
     * @return string
     */
    public function GetState() {
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesRadicale->GetState() icc not configured");
            return false;
        }
        return $this->icc->GetState();
    }

    /**
     * Sets the states from move operations.
     * When src and dst state are set, a MOVE operation is being executed.
     *
     * @param mixed         $srcState
     * @param mixed         (opt) $dstState, default: null
     *
     * @access public
     * @return boolean
     */
    public function SetMoveStates($srcState, $dstState = null) {
        ZLog::Write(LOGLEVEL_DEBUG, "ImportChangesRadicale->SetMoveStates()");
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesRadicale->SetMoveStates() icc not configured");
            return false;
        }
        $this->icc->SetMoveStates($srcState, $dstState);
        ZLog::Write(LOGLEVEL_DEBUG, "ImportChangesRadicale->SetMoveStates() success");
    }

    /**
     * Gets the states of special move operations.
     *
     * @access public
     * @return array(0 => $srcState, 1 => $dstState)
     */
    public function GetMoveStates() {
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesRadicale->GetMoveStates() icc not configured");
            return false;
        }
        return $this->icc->GetMoveStates();
    }
}


/**
 * The ImportHierarchyChangesRadicaleWrap class wraps the importer given in ExportChangesRadicale->Config.
 * It prepends the backendid to all folderids and checks foldertypes.
 */

class ImportHierarchyChangesRadicaleWrap {
    private $ihc;
    private $backend;
    private $backendid;

    /**
     * Constructor of the ImportChangesRadicale class
     *
     * @param string $backendid
     * @param object $backend
     * @param object $ihc
     *
     * @access public
     */
    public function __construct($backendid, &$backend, &$ihc) {
        ZLog::Write(LOGLEVEL_DEBUG, "ImportHierarchyChangesRadicaleWrap->__construct('$backendid',...)");
        $this->backendid = $backendid;
        $this->backend =& $backend;
        $this->ihc = &$ihc;
    }

    /**
     * Imports a change on a folder
     *
     * @param object        $folder         SyncFolder
     *
     * @access public
     * @return boolean/string               status/id of the folder
     */
    public function ImportFolderChange($folder) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportHierarchyChangesRadicaleWrap->ImportFolderChange('%s')", $folder->serverid));
        $folder->serverid = $this->backendid.$this->backend->config['delimiter'].$folder->serverid;
        if($folder->parentid != '0' || !empty($this->backend->config['backends'][$this->backendid]['subfolder'])){
            $folder->parentid = $this->backendid.$this->backend->config['delimiter'].$folder->parentid;
        }
        if(isset($this->backend->config['folderbackend'][$folder->type]) && $this->backend->config['folderbackend'][$folder->type] != $this->backendid){
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("not using folder: '%s' ('%s')", $folder->displayname, $folder->serverid));
            return true;
        }
        ZLog::Write(LOGLEVEL_DEBUG, "ImportHierarchyChangesRadicaleWrap->ImportFolderChange() success");
        return $this->ihc->ImportFolderChange($folder);
    }

    /**
     * Imports a folder deletion
     *
     * @param SyncFolder    $folder         at least "serverid" needs to be set
     *
     * @access public
     *
     * @return boolean/int  success/SYNC_FOLDERHIERARCHY_STATUS
     */
    public function ImportFolderDeletion($folder) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportHierarchyChangesRadicaleWrap->ImportFolderDeletion('%s')", $folder->serverid));
        $folder->serverid = $this->backendid . $this->backend->config['delimiter'] . $folder->serverid;
        return $this->ihc->ImportFolderDeletion($folder);
    }
}
