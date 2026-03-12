<?php
/***********************************************
* File      :   backend/radicale/config.php
* Project   :   Z-Push
* Descr     :   configuration file for the
*               radicale backend.
*
* Created   :   13.03.2026
************************************************/

class BackendRadicaleConfig {

    // *************************
    //  BackendRadicale settings
    // *************************
    /**
     * Returns the configuration of the radicale backend
     *
     * @access public
     * @return array
     *
     */
    public static function GetBackendRadicaleConfig() {
        //use a function for it because php does not allow
        //assigning variables to the class members (expecting T_STRING)
        return array(
            //the order in which the backends are loaded.
            //login only succeeds if all backend return true on login
            //sending mail: the mail is sent with first backend that is able to send the mail
            'backends' => array(
                'd' => array(
                    'name' => 'BackendCardDAV',
                ),
                'c' => array(
                    'name' => 'BackendCalDAV',
                ),
            ),
            'delimiter' => '/',
            //force one type of folder to one backend
            //it must match one of the above defined backends
            'folderbackend' => array(
                SYNC_FOLDER_TYPE_TASK => 'c',
                SYNC_FOLDER_TYPE_APPOINTMENT => 'c',
                SYNC_FOLDER_TYPE_CONTACT => 'd',
                SYNC_FOLDER_TYPE_USER_APPOINTMENT => 'c',
                SYNC_FOLDER_TYPE_USER_CONTACT => 'd',
                SYNC_FOLDER_TYPE_USER_TASK => 'c',
            ),
            //creating a new folder in the root folder should create a folder in one backend
            // 'rootcreatefolderbackend' => 'i',
        );
    }
}
