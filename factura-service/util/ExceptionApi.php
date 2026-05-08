<?php

/**
 * <b>Descripcion:</b> Clase que <br/> gestiona las excepciones

 * @author Miguel Angel Saenz Tibambre<a href = "mailto:miguel.saenz02@uptc.edu.co">miguel.saenz02@uptc.edu.co</a>
 */
class ExcepcionAPI extends Exception
{

    /**
     * Estado de la solicitud
     * 
     * @var string state
     */
    public $state;

    /**
     * Constructor de la clase
     */
    public function __construct($state, $code, $message)
    {
        $this->state = $state;
        $this->code = $code;
        $this->message = $message;
    }

    /**
     *
     * @return the $state
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     *
     * @param string $state
     */
    public function setState($state)
    {
        $this->state = $state;
    }
}
