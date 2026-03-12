<?php
/***********************************************
* File      :   backend/radicale/radicale.php
* Project   :   Z-Push
* Descr     :   Modified from combined for Radicale.
*
* Created   :   13.03.2026
************************************************/

// config file
require_once("backend/radicale/config.php");

class BackendRadicale extends Backend implements ISearchProvider {
    public $config;
    public $backends;
    private $activeBackend;
    private $activeBackendID;
    private $numberChangesSink;
    private $logon_done = false;

    /**
     * Constructor of the radicale backend
     *
     * @access public
     */
    public function __construct() {
        parent::__construct();
        $this->config = BackendRadicaleConfig::GetBackendRadicaleConfig();

        $backend_values = array_unique(array_values($this->config['folderbackend']));
        foreach ($backend_values as $i) {
            ZPush::IncludeBackend($this->config['backends'][$i]['name']);
            $this->backends[$i] = new $this->config['backends'][$i]['name']();
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Radicale %d backends loaded.", count($this->backends)));
    }

    /**
     * Authenticates the user on each backend
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     */
    public function Logon($username, $domain, $password) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Radicale->Logon('%s', '%s',***))", $username, $domain));
        if(!is_array($this->backends)){
            return false;
        }
        foreach ($this->backends as $i => $b){
            $u = $username;
            $d = $domain;
            $p = $password;
            if(isset($this->config['backends'][$i]['users'])){
                if(!isset($this->config['backends'][$i]['users'][$username])){
                    unset($this->backends[$i]);
                    continue;
                }
                if(isset($this->config['backends'][$i]['users'][$username]['username']))
                    $u = $this->config['backends'][$i]['users'][$username]['username'];
                if(isset($this->config['backends'][$i]['users'][$username]['password']))
                    $p = $this->config['backends'][$i]['users'][$username]['password'];
                if(isset($this->config['backends'][$i]['users'][$username]['domain']))
                    $d = $this->config['backends'][$i]['users'][$username]['domain'];
            }
            if($this->backends[$i]->Logon($u, $d, $p) == false){
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("Radicale->Logon() failed on %s ", $this->config['backends'][$i]['name']));
                return false;
            }
        }

        $this->logon_done = true;
        ZLog::Write(LOGLEVEL_DEBUG, "Radicale->Logon() success");
        return true;
    }

    /**
     * Setup the backend to work on a specific store or checks ACLs there.
     * If only the $store is submitted, all Import/Export/Fetch/Etc operations should be
     * performed on this store (switch operations store).
     * If the ACL check is enabled, this operation should just indicate the ACL status on
     * the submitted store, without changing the store for operations.
     * For the ACL status, the currently logged on user MUST have access rights on
     *  - the entire store - admin access if no folderid is sent, or
     *  - on a specific folderid in the store (secretary/full access rights)
     *
     * The ACLcheck MUST fail if a folder of the authenticated user is checked!
     *
     * @param string        $store              target store, could contain a "domain\user" value
     * @param boolean       $checkACLonly       if set to true, Setup() should just check ACLs
     * @param string        $folderid           if set, only ACLs on this folderid are relevant
     * @param boolean       $readonly           if set, the folder needs at least read permissions
     *
     * @access public
     * @return boolean
     */
    public function Setup($store, $checkACLonly = false, $folderid = false, $readonly = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Radicale->Setup('%s', '%s', '%s', '%s')", $store, Utils::PrintAsString($checkACLonly), $folderid, Utils::PrintAsString($readonly)));
        if(!is_array($this->backends)){
            return false;
        }
        foreach ($this->backends as $i => $b){
            $u = $store;
            if(isset($this->config['backends'][$i]['users']) && isset($this->config['backends'][$i]['users'][$store]['username'])){
                $u = $this->config['backends'][$i]['users'][$store]['username'];
            }
            if($this->backends[$i]->Setup($u, $checkACLonly, $folderid, $readonly) == false){
                ZLog::Write(LOGLEVEL_WARN, "Radicale->Setup() failed");
                return false;
            }
        }
        ZLog::Write(LOGLEVEL_DEBUG, "Radicale->Setup() success");
        return true;
    }

    /**
     * Logs off each backend
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        // If no Logon in done, omit Logoff
        if (!$this->logon_done)
            return true;

        ZLog::Write(LOGLEVEL_DEBUG, "Radicale->Logoff()");
        foreach ($this->backends as $i => $b){
            $this->backends[$i]->Logoff();
        }
        ZLog::Write(LOGLEVEL_DEBUG, "Radicale->Logoff() success");
        return true;
    }

    /**
     * Returns an array of SyncFolder types with the entire folder hierarchy
     * from all backends combined
     *
     * provides AS 1.0 compatibility
     *
     * @access public
     * @return array SYNC_FOLDER
     */
    public function GetHierarchy(){
        ZLog::Write(LOGLEVEL_DEBUG, "Radicale->GetHierarchy()");
        $ha = array();
        foreach ($this->backends as $i => $b){
            if(!empty($this->config['backends'][$i]['subfolder'])){
                $f = new SyncFolder();
                $f->serverid = $i.$this->config['delimiter'].'0';
                $f->parentid = '0';
                $f->displayname = $this->config['backends'][$i]['subfolder'];
                $f->type = SYNC_FOLDER_TYPE_OTHER;
                $ha[] = $f;
            }
            $h = $this->backends[$i]->GetHierarchy();
            if(is_array($h)){
                foreach($h as $j => $f){
                    $h[$j]->serverid = $i.$this->config['delimiter'].$h[$j]->serverid;
                    if($h[$j]->parentid != '0' || !empty($this->config['backends'][$i]['subfolder'])){
                        $h[$j]->parentid = $i.$this->config['delimiter'].$h[$j]->parentid;
                    }
                    if(isset($this->config['folderbackend'][$h[$j]->type]) && $this->config['folderbackend'][$h[$j]->type] != $i){
                        $h[$j]->type = SYNC_FOLDER_TYPE_OTHER;
                    }
                }
                $ha = array_merge($ha, $h);
            }
        }
        ZLog::Write(LOGLEVEL_DEBUG, "Radicale->GetHierarchy() success");
        return $ha;
    }

    /**
     * Returns the importer to process changes from the mobile
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ImportChanges)
     */
    public function GetImporter($folderid = false) {
        if($folderid !== false) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Radicale->GetImporter() Content: ImportChangesRadicale:('%s')", $folderid));

            // get the contents importer from the folder in a backend
            // the importer is wrapped to check foldernames in the ImportMessageMove function
            $backend = $this->GetBackend($folderid);
            if($backend === false)
                return false;
            $importer = $backend->GetImporter($this->GetBackendFolder($folderid));
            if($importer){
                return new ImportChangesRadicale($this, $folderid, $importer);
            }
            return false;
        }
        else {
            ZLog::Write(LOGLEVEL_DEBUG, "Radicale->GetImporter() -> Hierarchy: ImportChangesRadicale()");
            //return our own hierarchy importer which send each change to the right backend
            return new ImportChangesRadicale($this);
        }
    }

    /**
     * Returns the exporter to send changes to the mobile
     * the exporter from right backend for contents exporter and our own exporter for hierarchy exporter
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ExportChanges)
     */
    public function GetExporter($folderid = false){
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Radicale->GetExporter('%s')", $folderid));
        if($folderid){
            $backend = $this->GetBackend($folderid);
            if($backend == false)
                return false;
            return $backend->GetExporter($this->GetBackendFolder($folderid));
        }
        return new ExportChangesRadicale($this);
    }

    /**
     * Radicale doesn't need to handle SendMail
     * @see IBackend::SendMail()
     */
    public function SendMail($sm) {
        return false;
    }

    /**
     * Returns all available data of a single message
     *
     * @param string            $folderid
     * @param string            $id
     * @param ContentParameters $contentparameters flag
     *
     * @access public
     * @return object(SyncObject)
     * @throws StatusException
     */
    public function Fetch($folderid, $id, $contentparameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Radicale->Fetch('%s', '%s', CPO)", $folderid, $id));
        $backend = $this->GetBackend($folderid);
        if($backend == false)
            return false;
        return $backend->Fetch($this->GetBackendFolder($folderid), $id, $contentparameters);
    }

    /**
     * Deletes are always permanent deletes. Messages doesn't get moved.
     * @see IBackend::GetWasteBasket()
     */
    function GetWasteBasket(){
        return false;
    }

    /**
     * No attachments in Radicale
     * @see IBackend::GetAttachmentData()
     */
    public function GetAttachmentData($attname) {
        return false;
    }

    /**
     * Indicates if the backend has a ChangesSink.
     * A sink is an active notification mechanism which does not need polling.
     * The CalDAV backend simulates a sink by polling revision dates from the events or use the native sync-collection.
     *
     * @access public
     * @return boolean
     */
    public function HasChangesSink() {
        return true;
    }

    /**
     * The folder should be considered by the sink.
     * Folders which were not initialized should not result in a notification
     * of IBacken->ChangesSink().
     *
     * @param string        $folderid
     *
     * @access public
     * @return boolean      false if there is any problem with that folder
     */
     public function ChangesSinkInitialize($folderid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendRadicale->ChangesSinkInitialize('%s')", $folderid));

        $backend = $this->GetBackend($folderid);
        if($backend === false) {
            // if not backend is found we return true, we don't want this to cause an error
            return true;
        }

        if ($backend->HasChangesSink()) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendRadicale->ChangesSinkInitialize('%s') is supported, initializing", $folderid));
            return $backend->ChangesSinkInitialize($this->GetBackendFolder($folderid));
        }

        // if the backend doesn't support ChangesSink, we also return true so we don't get an error
        return true;
     }

    /**
     * The actual ChangesSink.
     * For max. the $timeout value this method should block and if no changes
     * are available return an empty array.
     * If changes are available a list of folderids is expected.
     *
     * @param int           $timeout        max. amount of seconds to block
     *
     * @access public
     * @return array
     */

    public function ChangesSink($timeout = 30) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendRadicale->ChangesSink(%d)", $timeout));

        $notifications = array();
        if ($this->numberChangesSink == 0) {
            ZLog::Write(LOGLEVEL_DEBUG, "BackendRadicale doesn't include any Sinkable backends");
        } else {
            $stopat = time() + $timeout - 1;

            foreach ($this->backends as $i => $b) {
                if ($this->backends[$i]->HasChangesSink()) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendRadicale->ChangesSink - Calling in '%s'", get_class($b)));

                    $notifications_backend = $this->backends[$i]->ChangesSink(1);
                    // prepend backend delimiter
                    for ($c = 0; $c < count($notifications_backend); $c++) {
                        $notifications_backend[$c] = $i . $this->config['delimiter'] . $notifications_backend[$c];
                    }
                    $notifications = array_merge($notifications, $notifications_backend);
                }
            }

            // If nothing changed, wait until timeout
            if (empty($notifications)) {
                while ($stopat > time()) {
                    sleep(1);
                }
            }
        }

        return $notifications;
    }

    /**
     * Finds the correct backend for a folder
     *
     * @param string        $folderid       combinedid of the folder
     *
     * @access public
     * @return object
     */
    public function GetBackend($folderid){
        $pos = strpos($folderid, $this->config['delimiter']);
        if($pos === false)
            return false;
        $id = substr($folderid, 0, $pos);
        if(!isset($this->backends[$id]))
            return false;

        $this->activeBackend = $this->backends[$id];
        $this->activeBackendID = $id;
        return $this->backends[$id];
    }

    /**
     * Returns an understandable folderid for the backend
     *
     * @param string        $folderid       combinedid of the folder
     *
     * @access public
     * @return string
     */
    public function GetBackendFolder($folderid){
        $pos = strpos($folderid, $this->config['delimiter']);
        if($pos === false)
            return false;
        return substr($folderid,$pos + strlen($this->config['delimiter']));
    }

    /**
     * Returns backend id for a folder
     *
     * @param string        $folderid       combinedid of the folder
     *
     * @access public
     * @return object
     */
    public function GetBackendId($folderid){
        $pos = strpos($folderid, $this->config['delimiter']);
        if($pos === false)
            return false;
        return substr($folderid, 0, $pos);
    }

    /**
     * Indicates which AS version is supported by the backend.
     * Return the lowest version supported by the backends used.
     *
     * @access public
     * @return string       AS version constant
     */
    public function GetSupportedASVersion() {
        $version = ZPush::ASV_14;
        foreach ($this->backends as $i => $b) {
            $subversion = $this->backends[$i]->GetSupportedASVersion();
            if ($subversion < $version) {
                $version = $subversion;
            }
        }
        return $version;
    }

    /**
     * Returns the BackendRadicale as it implements the ISearchProvider interface
     * This could be overwritten by the global configuration
     *
     * @access public
     * @return object       Implementation of ISearchProvider
     */
    public function GetSearchProvider() {
        return $this;
    }


    /*-----------------------------------------------------------------------------------------
    -- ISearchProvider
    ------------------------------------------------------------------------------------------*/
    /**
     * Indicates if a search type is supported by this SearchProvider
     * It supports all the search types, searches are delegated.
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Radicale->SupportsType('%s')", $searchtype));
        $i = $this->getSearchBackend($searchtype);

        return $i !== false;
    }


    /**
     * Queries the LDAP backend
     *
     * @param string                        $searchquery        string to be searched for
     * @param string                        $searchrange        specified searchrange
     * @param SyncResolveRecipientsPicture  $searchpicture      limitations for picture
     *
     * @access public
     * @return array        search results
     * @throws StatusException
     */
    public function GetGALSearchResults($searchquery, $searchrange, $searchpicture) {
        ZLog::Write(LOGLEVEL_DEBUG, "Radicale->GetGALSearchResults()");
        $i = $this->getSearchBackend(ISearchProvider::SEARCH_GAL);

        $result = false;
        if ($i !== false) {
            $result = $this->backends[$i]->GetGALSearchResults($searchquery, $searchrange, $searchpicture);
        }

        return $result;
    }


    /**
    * Terminates a search for a given PID
    *
    * @param int $pid
    *
    * @return boolean
    */
    public function TerminateSearch($pid) {
        ZLog::Write(LOGLEVEL_DEBUG, "Radicale->TerminateSearch()");
        foreach ($this->backends as $i => $b) {
            if ($this->backends[$i] instanceof ISearchProvider) {
                $this->backends[$i]->TerminateSearch($pid);
            }
        }

        return true;
    }


    /**
     * Disconnects backends
     *
     * @access public
     * @return boolean
     */
    public function Disconnect() {
        ZLog::Write(LOGLEVEL_DEBUG, "Radicale->Disconnect()");
        foreach ($this->backends as $i => $b) {
            if ($this->backends[$i] instanceof ISearchProvider) {
                $this->backends[$i]->Disconnect();
            }
        }

        return true;
    }


    /**
     * Returns the first backend that support a search type
     *
     * @param string    $searchtype
     *
     * @access private
     * @return string
     */
    private function getSearchBackend($searchtype) {
        foreach ($this->backends as $i => $b) {
            if ($this->backends[$i] instanceof ISearchProvider) {
                if ($this->backends[$i]->SupportsType($searchtype)) {
                    return $i;
                }
            }
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Radicale->getSearchBackend('%s') No support found!", $searchtype));

        return false;
    }

    /**
     * Returns the email address and the display name of the user. Used by autodiscover and caldav.
     *
     * @param string        $username           The username
     *
     * @access public
     * @return Array
     */
    public function GetUserDetails($username) {
        // Find a backend that can provide the information
        foreach ($this->backends as $backend) {
            if (method_exists($backend, "GetUserDetails")) {
                return $backend->GetUserDetails($username);
            }
        }
        return parent::GetUserDetails($username);
    }
}
