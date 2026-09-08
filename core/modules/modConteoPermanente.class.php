<?php
/**
 *  \file       htdocs/custom/conteopermanente/core/modules/modConteoPermanente.class.php
 *  \ingroup    conteopermanente
 *  \brief      Descriptor del módulo Conteo Permanente
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modConteoPermanente extends DolibarrModules
{
    /**
     * Constructor del módulo
     *
     * @param DoliDB $db Objeto de conexión a base de datos
     */
    public function __construct($db)
    {
        global $langs;

        $this->db = $db;

        // Identificación del Módulo
        $this->numero = 500001; // ID único (elige un número alto dentro del rango 500000+ para evitar conflictos)
        $this->rights_class = 'conteopermanente';
        $this->family = "products";
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Pantalla liviana de consulta e inventario continuo para dispositivos móviles";
        
        // Versión y Estado
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->special = 0;
        $this->picto = 'product';

        // Directorios de código e idioma
        $this->dirs = array('/conteopermanente');

        // Configuración de dependencias
        $this->depends = array('modProduct'); // Depende del módulo nativo de Productos
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("products");

        // Datos del Autor
        $this->editor_name = 'Tu Nombre o Empresa';
        $this->editor_url = '';

        // Definición de Permisos
        $this->rights = array();
        $r = 0;

        $this->rights[$r][0] = 500001; // ID Permiso
        $this->rights[$r][1] = 'Permite acceder a la pantalla de Conteo Permanente';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 1; // Habilitado por defecto para admins
        $this->rights[$r][4] = 'leer';
        $this->rights[$r][5] = '';
        $r++;

        // Definición de Menús
        $this->menu = array();
        $m = 0;

        // Menú Lateral dentro de la pestaña "Productos"
        $this->menu[$m] = array(
            'fk_menu' => 'fk_mainmenu=products',                     // Vinculado al menú principal de Productos
            'type' => 'left',                                         // Menú lateral izquierdo
            'titre' => 'Conteo permanente',
            'mainmenu' => 'products',
            'leftmenu' => 'conteo_permanente',
            'url' => '/custom/conteopermanente/conteo_permanente.php" target="_blank', // Fuerza target _blank
            'langs' => 'products',
            'position' => 1000,
            'enabled' => '$conf->conteopermanente->enabled',
            'perms' => '$user->rights->conteopermanente->leer && $user->rights->produit->lire',
            'target' => '_blank',                                     // Asegura apertura independiente
            'user' => 2                                               // Visibilidad para usuarios internos/externos
        );
        $m++;
    }

    /**
     * Función ejecutada al activar el módulo
     *
     * @param string $options
     * @return int 1 si todo ok, 0 si hay error
     */
    public function init($options = '')
    {
        $sql = array();
        return $this->_init($sql, $options);
    }

    /**
     * Función ejecutada al desactivar el módulo
     *
     * @param string $options
     * @return int 1 si todo ok, 0 si hay error
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}