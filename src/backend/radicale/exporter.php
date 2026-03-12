<?php
/***********************************************
* File      :   backend/radicale/exporter.php
* Project   :   Z-Push
* Descr     :   Exporter class for the radicale backend.
*
* Created   :   13.03.2026
************************************************/

/**
 * the ExportChangesRadicale class is returned from GetExporter for changes.
 * It combines the changes from all backends and prepends all folderids with the backendid
 */

class ExportChangesRadicale implements IExportChanges {
    private $backend;
    private $syncstates;
    private $movestateSrc;
    private $movestateDst;
    private $exporters;
    private $importer;
    private $importwraps;

    public function __construct(&$backend) {
        $this->backend =& $backend;
        $this->exporters = array();
        $this->movestateSrc = false;
        $this->movestateDst = false;
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale constructed");
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
    public function Config($syncstate, $flags = 0) {
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->Config(...)");
        $this->syncstates = $syncstate;
        if(!is_array($this->syncstates)){
            $this->syncstates = array();
        }

        foreach($this->backend->backends as $i => $b){
            if(isset($this->syncstates[$i])){
                $state = $this->syncstates[$i];
            } else {
                $state = '';
            }

            $this->exporters[$i] = $this->backend->backends[$i]->GetExporter();
            $this->exporters[$i]->Config($state, $flags);
        }
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->Config() success");
    }

    /**
     * Returns the amount of changes to be exported
     *
     * @access public
     * @return int
     */
    public function GetChangeCount() {
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->GetChangeCount()");
        $c = 0;
        foreach($this->exporters as $i => $e){
            $c += $this->exporters[$i]->GetChangeCount();
        }
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->GetChangeCount() success");
        return $c;
    }

    /**
     * Synchronizes a change to the configured importer
     *
     * @access public
     * @return array        with status information
     */
    public function Synchronize() {
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->Synchronize()");
        foreach($this->exporters as $i => $e){
            if(!empty($this->backend->config['backends'][$i]['subfolder']) && !isset($this->syncstates[$i])){
                // first sync and subfolder backend
                $f = new SyncFolder();
                $f->serverid = $i.$this->backend->config['delimiter'].'0';
                $f->parentid = '0';
                $f->displayname = $this->backend->config['backends'][$i]['subfolder'];
                $f->type = SYNC_FOLDER_TYPE_OTHER;
                $this->importer->ImportFolderChange($f);
            }
            while(is_array($this->exporters[$i]->Synchronize()));
        }
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->Synchronize() success");
        return true;
    }

    /**
     * Reads and returns the current state
     *
     * @access public
     * @return string
     */
    public function GetState() {
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->GetState()");
        foreach($this->exporters as $i => $e){
            $this->syncstates[$i] = $this->exporters[$i]->GetState();
        }
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->GetState() success");
        return $this->syncstates;
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
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->ConfigContentParameters()");
        foreach($this->exporters as $i => $e){
            //call the ConfigContentParameters() of each exporter
            $e->ConfigContentParameters($contentparameters);
        }
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->ConfigContentParameters() success");
    }

    /**
     * Sets the importer where the exporter will sent its changes to
     * This exporter should also be ready to accept calls after this
     *
     * @param object        &$importer      Implementation of IImportChanges
     *
     * @access public
     * @return boolean
     */
    public function InitializeExporter(&$importer) {
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->InitializeExporter(...)");
        foreach ($this->exporters as $i => $e) {
            if(!isset($this->importwraps[$i])){
                $this->importwraps[$i] = new ImportHierarchyChangesRadicaleWrap($i, $this->backend, $importer);
            }
            $e->InitializeExporter($this->importwraps[$i]);
        }
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->InitializeExporter(...) success");
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
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->SetMoveStates()");
        // Set the move states equally to every sub exporter.
        // By default they are false or null, we know that they've changed, if the exporter will return a different value.
        foreach($this->exporters as $i => $e){
            $e->SetMoveStates($srcState, $dstState);
        }
        // let's remember what we sent down
        $this->movestateSrc = $srcState;
        $this->movestateDst = $dstState;
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->SetMoveStates() success");
    }

    /**
     * Gets the states of special move operations.
     *
     * @access public
     * @return array(0 => $srcState, 1 => $dstState)
    */
    public function GetMoveStates() {
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->GetMoveStates()");
        foreach($this->exporters as $i => $e){
            list($srcState, $dstState) = $this->exporters[$i]->GetMoveStates();
            if ($srcState != $this->movestateSrc || $dstState != $this->movestateDst) {
                ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->GetMoveStates() success (returned states from exporter $i)");
                return array($srcState, $dstState);
            }
        }
        ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesRadicale->GetMoveStates() success (no movestate)");
        return false;
    }
}
