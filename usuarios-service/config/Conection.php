<?php

class Conection
{

    private static $db = null;

    private static $pdo;

    private final function __construct()
    {
        try {
            self::getConection();
        } catch (PDOException $e) {
            throw new ExcepcionAPI("Error de conexión a la base de datos", 500, $e->getMessage());
        }
    }

    /**
     * Garantiza tener una única instancia en la case
     * @return Conection
     */
    public static function getInstance()
    {
        if (self::$db == null) {
            self::$db = new self();
        }
        return self::$db;
    }

    public static function getConection()
    {
        if (self::$pdo == null) {
            self::$pdo = new PDO('mysql:dbname=' . 'reservas' . ';host=localhost:3013;', 'root', 'Niosaenz123', array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES UTF8"));
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return self::$pdo;
    }

    function __destruct()
    {
        self::$pdo = null;
    }
}