<?php
/* Copyright (C) 2026
 *
 * Modul "Arbeitszeiterfassung" (worktime) für Dolibarr 14.x – 18.x
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \defgroup   worktime     Modul Arbeitszeiterfassung
 * \file       core/modules/modWorktime.class.php
 * \ingroup    worktime
 * \brief      Modul-Deskriptor: registriert Rechte, Menüs, Konstanten und Tabellen
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Beschreibungs- und Aktivierungsklasse für das Modul Worktime
 */
class modWorktime extends DolibarrModules
{
        /**
         * Konstruktor
         *
         * @param DoliDB $db Datenbank-Handler
         */
        public function __construct($db)
        {
                global $langs, $conf;

                $this->db = $db;

                // Eindeutige Modulnummer (Bereich 500000+ ist für externe Module vorgesehen)
                $this->numero = 500210;
                $this->rights_class = 'worktime';
                $this->family = 'hr';
                $this->module_position = 90;
                $this->name = preg_replace('/^mod/i', '', get_class($this));
                $this->description = 'Time tracking: clock in/out/break, vacation, sickness, work-time account and reports';
                $this->descriptionlong = 'Time clock inside Dolibarr linked to the logged-in user, vacation and sick-leave management, plus/minus time account and weekly/monthly reports.';
                $this->editor_name = 'Worktime';
                $this->editor_url = '';
                $this->version = '1.2.0';
                $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
                $this->picto = 'worktime@worktime';

                $this->module_parts = array(
                        'triggers' => 0,
                        'login' => 0,
                        'substitutions' => 0,
                        'menus' => 0,
                        'theme' => 0,
                        'tpl' => 0,
                        'barcode' => 0,
                        'models' => 0,
                        'css' => array(),
                        'js' => array(),
                        'hooks' => array(),
                        'moduleforexternal' => 0,
                );

                $this->dirs = array();
                $this->config_page_url = array('setup.php@worktime');

                $this->hidden = false;
                $this->depends = array();
                $this->requiredby = array();
                $this->conflictwith = array();
                $this->langfiles = array('worktime@worktime');
                $this->phpmin = array(8, 1);
                $this->need_dolibarr_version = array(23, 0);
                $this->warnings_activation = array();
                $this->warnings_activation_ext = array();

                // Konstanten (Standardwerte), werden bei Aktivierung angelegt
                $this->const = array(
                        1 => array('WORKTIME_DEFAULT_WEEKLY_HOURS', 'chaine', '40', 'Default weekly hours', 0, 'current', 0),
                        2 => array('WORKTIME_DEFAULT_VACATION_DAYS', 'chaine', '30', 'Default vacation days per year', 0, 'current', 0),
                        3 => array('WORKTIME_HOLIDAY_STATE', 'chaine', 'CY', 'Holiday rule (CY = Cyprus)', 0, 'current', 0),
                        4 => array('WORKTIME_HOLIDAYS_MANUAL', 'chaine', '', 'Manual holidays (one line per date YYYY-MM-DD)', 0, 'current', 0),
                        5 => array('WORKTIME_VACATION_CARRYOVER_DATE', 'chaine', '03-31', 'Vacation carry-over deadline (MM-DD)', 0, 'current', 0),
                        6 => array('WORKTIME_SICK_CERT_DAYS', 'chaine', '3', 'Certificate required from X sick days', 0, 'current', 0),
                );

                $this->tabs = array();
                $this->dictionaries = array();
                $this->boxes = array();
                $this->cronjobs = array();

                // ---------------------------------------------------------------
                // Berechtigungen
                // ---------------------------------------------------------------
                $this->rights = array();
                $r = 0;

                $this->rights[$r][0] = $this->numero + $r + 1;
                $this->rights[$r][1] = 'View own working times and reports';
                $this->rights[$r][2] = 'r';
                $this->rights[$r][3] = 1; // standardmäßig für neue Benutzer aktiv
                $this->rights[$r][4] = 'read';
                $this->rights[$r][5] = '';
                $r++;

                $this->rights[$r][0] = $this->numero + $r + 1;
                $this->rights[$r][1] = 'Clock in/out/break and request absences';
                $this->rights[$r][2] = 'w';
                $this->rights[$r][3] = 1;
                $this->rights[$r][4] = 'write';
                $this->rights[$r][5] = '';
                $r++;

                $this->rights[$r][0] = $this->numero + $r + 1;
                $this->rights[$r][1] = 'Administration: view/correct all times, approve requests, settings';
                $this->rights[$r][2] = 'w';
                $this->rights[$r][3] = 0;
                $this->rights[$r][4] = 'admin';
                $this->rights[$r][5] = '';
                $r++;

                // ---------------------------------------------------------------
                // Menüs
                // ---------------------------------------------------------------
                $this->menu = array();
                $r = 0;

                $this->menu[$r++] = array(
                        'fk_menu' => '',
                        'type' => 'top',
                        'titre' => 'WorktimeMenuTop',
                        'mainmenu' => 'worktime',
                        'leftmenu' => '',
                        'url' => '/worktime/index.php',
                        'langs' => 'worktime@worktime',
                        'position' => 1000 + $r,
                        'enabled' => "isModEnabled('worktime')",
                        'perms' => '$user->hasRight("worktime", "read")',
                        'target' => '',
                        'user' => 0,
                );

                $this->menu[$r++] = array(
                        'fk_menu' => 'fk_mainmenu=worktime',
                        'type' => 'left',
                        'titre' => 'WorktimeTimeclock',
                        'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
                        'mainmenu' => 'worktime',
                        'leftmenu' => 'worktime_clock',
                        'url' => '/worktime/index.php',
                        'langs' => 'worktime@worktime',
                        'position' => 1000 + $r,
                        'enabled' => "isModEnabled('worktime')",
                        'perms' => '$user->hasRight("worktime", "read")',
                        'target' => '',
                        'user' => 0,
                );

                $this->menu[$r++] = array(
                        'fk_menu' => 'fk_mainmenu=worktime,fk_leftmenu=worktime_clock',
                        'type' => 'left',
                        'titre' => 'WorktimeWeeklyReport',
                        'mainmenu' => 'worktime',
                        'leftmenu' => 'worktime_week',
                        'url' => '/worktime/report_weekly.php',
                        'langs' => 'worktime@worktime',
                        'position' => 1000 + $r,
                        'enabled' => "isModEnabled('worktime')",
                        'perms' => '$user->hasRight("worktime", "read")',
                        'target' => '',
                        'user' => 0,
                );

                $this->menu[$r++] = array(
                        'fk_menu' => 'fk_mainmenu=worktime,fk_leftmenu=worktime_clock',
                        'type' => 'left',
                        'titre' => 'WorktimeMonthlyReport',
                        'mainmenu' => 'worktime',
                        'leftmenu' => 'worktime_month',
                        'url' => '/worktime/report_monthly.php',
                        'langs' => 'worktime@worktime',
                        'position' => 1000 + $r,
                        'enabled' => "isModEnabled('worktime')",
                        'perms' => '$user->hasRight("worktime", "read")',
                        'target' => '',
                        'user' => 0,
                );

                $this->menu[$r++] = array(
                        'fk_menu' => 'fk_mainmenu=worktime,fk_leftmenu=worktime_clock',
                        'type' => 'left',
                        'titre' => 'WorktimeAbsences',
                        'mainmenu' => 'worktime',
                        'leftmenu' => 'worktime_absence',
                        'url' => '/worktime/absence.php',
                        'langs' => 'worktime@worktime',
                        'position' => 1000 + $r,
                        'enabled' => "isModEnabled('worktime')",
                        'perms' => '$user->hasRight("worktime", "read")',
                        'target' => '',
                        'user' => 0,
                );

                $this->menu[$r++] = array(
                        'fk_menu' => 'fk_mainmenu=worktime,fk_leftmenu=worktime_clock',
                        'type' => 'left',
                        'titre' => 'WorktimeSetup',
                        'mainmenu' => 'worktime',
                        'leftmenu' => 'worktime_setup',
                        'url' => '/worktime/admin/setup.php',
                        'langs' => 'worktime@worktime',
                        'position' => 1000 + $r,
                        'enabled' => "isModEnabled('worktime')",
                        'perms' => '$user->hasRight("worktime", "admin")',
                        'target' => '',
                        'user' => 0,
                );
        }

        /**
         * Wird beim Aktivieren des Moduls aufgerufen: legt Tabellen, Konstanten,
         * Rechte und Menüs an.
         *
         * @param  string $options Optionen
         * @return int             1 bei Erfolg, 0 bei Fehler
         */
        public function init($options = '')
        {
                $result = $this->_load_tables('/worktime/sql/');
                if ($result < 0) {
                        return -1;
                }

                $this->remove($options);

                $sql = array();
                return $this->_init($sql, $options);
        }

        /**
         * Wird beim Deaktivieren aufgerufen. Daten bleiben erhalten.
         *
         * @param  string $options Optionen
         * @return int             1 bei Erfolg, 0 bei Fehler
         */
        public function remove($options = '')
        {
                $sql = array();
                return $this->_remove($sql, $options);
        }
}
