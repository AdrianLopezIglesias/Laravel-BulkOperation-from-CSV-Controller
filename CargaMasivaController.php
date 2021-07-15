<?php

namespace App\Http\Controllers;

//MODELOS
use App\Helpers\ControlarPersonaHelper;
use App\Helpers\CSVHelper;
use App\Helpers\DateHelper;
use App\Helpers\StringHelper;
use App\Models\Actividad;
use App\Models\Administracion;
use App\Models\DefinicionApellido;
use App\Models\DefinicionNombre;
use App\Models\DefinicionVinculos;
use App\Models\Deuda;
use App\Models\Domicilio;
use App\Models\EntidadBancaria;
use App\Models\Identificacion;
use App\Models\Nota;
use App\Models\Persona;
use App\Models\Requerimiento;
use App\Models\Telefono;
use App\Models\Vinculo;
use Carbon\Carbon;
use File;
use Illuminate\Http\Request;

class CargaMasivaController extends Controller {
    public function __construct() {
        $this->middleware('auth');
    }
    public $mensaje = "";    
    /**
     * Method procesarCSV: Realiza el procesamiento básico del CSV en formato establecido.
     *
     * @param $archivo $archivo [Archivo CSV standard]
     *
     * @return array
     */
    private function procesarCSV($archivo) {
        ini_set('max_execution_time', 600);
        $archivo->store('uploads', ['disk' => 'public']);
        $personas = CSVHelper::csvToArray($archivo->getRealPath());
        if (\File::exists($archivo)) {
            unlink(public_path($archivo->store('uploads', ['disk' => 'public'])));
        } else {
            //
        }
        return $personas;
    }
    public function arreglarApellidos($personas) {
        $i = 0;
        foreach ($personas as $persona) {
            if (GeneroController::find($persona['genero']) != "juridica") {
                $apellido = "";
                $nombre = "";
                if (strlen($persona['apellido']) == 0 && $persona['nombre'] != "") {
                    $palabras = explode(" ", $persona['nombre']);
                    foreach ($palabras as $palabra) {
                        $palabra = StringHelper::remove_accents($palabra);
                        if (!DefinicionNombre::where('nombre', ucfirst($palabra))->exists()) {
                            $apellido .= $palabra . " ";
                        } else {
                            $nombre .= $palabra . " ";
                        }
                    }
                    $personas[$i]['apellido'] = $apellido;
                    $personas[$i]['nombre'] = $nombre;
                }
            }
            $i++;
        }
        return $personas;
    }
    public function procesarPersonas($personas) {
        for ($i = 0; $i < count($personas); $i++) {
            if($personas[$i]['nombre'] != ""){
                $personas[$i]['id'] = $i;
                $personas[$i] = ControlarPersonaHelper::verificarEstado($personas[$i]);
                switch ($personas[$i]['estado']) {
                    case 'Nueva':
                        try {
                            $personas[$i]['id'] = $this->nueva_persona($personas[$i]);
                            if($personas[$i]['id'] != 0){
                                $personas[$i]['mensaje'] .= $this->complementar_persona($personas[$i]);
                            }
                        } 
                        catch (\Throwable $e) {
                            $personas[$i]['estado'] = "Omitida";
                            $personas[$i]['mensaje'] .= "Error al ingresar esta persona";
                        }
                        break;
                    case 'Complemento':
                        try {
                            $personas[$i]['mensaje'] .= $this->complementar_persona($personas[$i]);
                        } 
                        catch (\Throwable $e) {
                            $personas[$i]['estado'] = "Omitida";
                            $personas[$i]['mensaje'] .= "Error al complementar datos de esta persona. $e";
                        }
                        break;  
                    default:
                        //
                        break;
                }
            }else{
                $personas[$i]['estado'] = "Vacio";
            }
        }
        return $personas;
    }
    
    /**
     * Method ingresarNombres Función especial para ingresar nombres en el Sistema atravez de un CSV aprovchando la infraestructura actual del sistema. Este metodo es opcional y por default no se utiliza. Hay que descomentar function prepare.
     *
     * @param Request $request [explicite description]
     *
     * @return void
     */
    public function ingresarNombres(Request $request){
        $nombres = $this->procesarCSV($request->file('archivo'));
        $i = 0;
        while ($nombres[$i]['cantidad'] > 1) {
            $palabras = explode(" ", $nombres[$i]['nombre']);
            foreach ($palabras as $palabra) {
                $palabra = strtoupper(StringHelper::remove_accents($palabra));
                if (!DefinicionNombre::where('nombre', $palabra)->exists() && $palabra != "") {
                    try {
                        $nuevoNombre = new DefinicionNombre;
                        $nuevoNombre->nombre = $palabra;
                        $nuevoNombre->save();
                        echo $palabra . "<br>";
                    } catch (\Throwable $e) {
                        echo "error: " . $palabra . "<br>";
                    }
                }
            }
            $i++;
        }
    }

    public function prepare(Request $request) {
        //$this->ingresarNombres($request);   
        //return;     
        $database = new DataBaseController;
        $database->backup_server($request);
        $personas = $this->procesarCSV($request->file('archivo'));
        if($request->normalizar_nombres){
            $personas = $this->arreglarApellidos($personas);
        }
        $personas = $this->procesarPersonas($personas);
        return view('carga_masiva.result', ['personas' => $personas]);
        
    }
    public function complementar_persona($persona) {
        $mensaje = "";
        ini_set('max_execution_time', 600);
        $mensaje .= CargaMasivaComplementosController::identificacion($persona);
        $mensaje .= CargaMasivaComplementosController::domicilio($persona);
        $mensaje .= CargaMasivaComplementosController::nota($persona);
        $mensaje .= CargaMasivaComplementosController::actividad($persona);
        $mensaje .= CargaMasivaComplementosController::requerimiento($persona);
        $mensaje .= CargaMasivaComplementosController::telefono($persona);
        $mensaje .= CargaMasivaComplementosController::deuda($persona);
        $mensaje .= CargaMasivaComplementosController::vinculo($persona);
        $mensaje .= CargaMasivaComplementosController::Administracion($persona);
        return $mensaje;
    }
  
    public function nueva_persona($persona) {
        ini_set('max_execution_time', 600);
        $persona_nueva = new Persona;
        if (isset($persona["nombre"])) {
            try {
                $persona_nueva->nombre = StringHelper::remove_accents($persona["nombre"]);
            }catch (\Throwable $e){
                $mensaje_error = "Error al actualizar el nombre. <br>";
            }
        }
        if (isset($persona["apellido"])) {
            try {
                $persona_nueva->apellido = StringHelper::remove_accents($persona["apellido"]);
            }catch (\Throwable $e){
                $mensaje_error = "Error al actualizar el apellido. <br>";
            }
        }
        if (isset($persona["fecha_nacimiento"])) {
            if ($persona["fecha_nacimiento"] != "") {
                try {
                    $fecha_nacimiento = DateHelper::SpanishToSQL($persona['fecha_nacimiento']);
                    $persona_nueva->fecha_nacimiento = $fecha_nacimiento['fecha'];
                    $mensaje_error = $fecha_nacimiento['mensaje'];
                }catch (\Throwable $e){
                    $mensaje_error = "Error al actualizar la fecha de nacimiento. <br>";
                }
            }
        }
        if (isset($persona["genero"])) {
            try {
                $persona_nueva->genero = GeneroController::find($persona['genero']);
            }catch (\Throwable $e){
                $mensaje_error = "Error al actualizar el genero. <br>";
            }
        }
        if (isset($persona["nacionalidad"])) {
            try {
                if (is_int($persona['nacionalidad'])) {
                    $persona_nueva->nacionalidad = $persona["nacionalidad"];
                } else {
                    $persona_nueva->nacionalidad = PaisController::find($persona['nacionalidad']);
                }
            }catch (\Throwable $e)
            {
                $mensaje_error = "Error al actualizar la nacionalidad. <br>";
            }
            try {
                $persona_nueva->save();
                return $persona_nueva->id;
            }catch (\Throwable $e){
                $mensaje_error = "Error al actualizar la persona. <br>";
            }
        }
        return 0;
    }
    public function update(Request $request) {
        switch ($request->accion) {
        case 'nueva':
            $personas = json_decode($request->personas, true);
            $personas[$request->persona_orden]['id'] = $this->nueva_persona($personas[$request->persona_orden]);
            $personas[$request->persona_orden]['estado'] = "Nueva";

            $grupo = new \stdClass();
            $grupo->definicion = "agregados";
            $grupo->personas = $personas;
            $grupo = json_encode($grupo);

            $result = [
                'error' => false,
                'resultado' => 'Persona creada  con exito',
                'action' => 'render',
                'data' => ['definicion' => 'agregados', 'personas' => $grupo],
                'url' => "/carga_masiva/renderTable",
                'target' => 'agregados',
            ];
            return json_encode($result);

            break;

        case 'complementar':
            $personas = json_decode($request->personas, true);
            $personas[$request->persona_orden]['id'] = $request->coincidencia_id;
            $personas[$request->persona_orden]['mensaje'] = $this->complementar_persona($personas[$request->persona_orden]);
            $personas[$request->persona_orden]['estado'] = "Complemento";

            $grupo = new \stdClass();
            $grupo->definicion = "complementados";
            $grupo->personas = $personas;
            $grupo = json_encode($grupo);

            $result = [
                'error' => false,
                'resultado' => 'Persona complementada con exito',
                'action' => 'render',
                'data' => ['definicion' => 'complementados', 'personas' => $grupo],
                'url' => "/carga_masiva/renderTable",
                'target' => 'complementados',
            ];
            return json_encode($result);
            break;
        case 'omitir':
            $personas = json_decode($request->personas, true);
            $personas[$request->persona_orden]['estado'] = "Omitida";

            $grupo = new \stdClass();
            $grupo->definicion = "omitidos";
            $grupo->personas = $personas;
            $grupo = json_encode($grupo);
            $result = [
                'error' => false,
                'resultado' => 'Persona omitida',
                'action' => 'render',
                'data' => ['definicion' => 'omitidos', 'personas' => $grupo],
                'url' => "/carga_masiva/renderTable",
                'target' => 'omitidos',
            ];
            return json_encode($result);
            break;
        default:
            return "Por favor ingrese seleccione una opción.";
            break;
        }
    }
    public function renderTable(Request $request) {
        $grupo = json_decode($request->personas, true);
        $personas = $grupo['personas'];
        switch ($grupo['definicion']) {
        case "agregados":
            //$personas = collect($personas)->where('estado', 'Nueva')->all();
            return view('carga_masiva.agregados.tabla', ['personas' => $personas]);
            break;
        case "complementados":
            //TODO
            return view('carga_masiva.complementados.tabla', ['personas' => $personas]);
            break;
        case "omitidos":
            //TODO
            return view('carga_masiva.omitidos.tabla', ['personas' => $personas]);
            break;
        }
    }
    public function render_agregados(Request $request) {
        $personas = $request->personas;
        return view('carga_masiva.agregados.tabla', ['personas' => $personas]);
    }
    public function render_omitidos(Request $request) {
        $personas = $request->personas;
        return view('carga_masiva.omitidos.tabla', ['personas' => $personas]);
    }
}
