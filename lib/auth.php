<?php
function login($conn)
{
    $id=entero($_POST['usuario_id']??null,'Usuario');
    $clave=$_POST['clave']??'';
    if (!is_string($clave) || strlen($clave)>1024) throw new DomainException('Credenciales incorrectas.');
    $u=fila($conn,"SELECT id,nombre,encode(clave,'hex') AS clave FROM usuarios WHERE id=$1 AND activo",[$id]);
    $stored=$u?hex2bin($u['clave']):'';
    $legacy=strlen($stored)===32;
    $ok=$legacy?hash_equals($stored,hash('sha256',$clave,true)):password_verify($clave,$stored);
    $roles=$u?roles_usuario($conn,$id):[];
    if (!$ok || !array_intersect($roles,['Administrador','Maitre','Mesero','Cocinero'])) {
        usleep(250000);
        throw new DomainException('Credenciales incorrectas o usuario sin acceso de empleado.');
    }
    if ($legacy || password_needs_rehash($stored,PASSWORD_DEFAULT)) {
        db($conn,"UPDATE usuarios SET clave=decode($1,'hex'),fecha_clave=LOCALTIMESTAMP WHERE id=$2 AND clave=decode($3,'hex')",[bin2hex(password_hash($clave,PASSWORD_DEFAULT)),$id,$u['clave']]);
    }
    session_regenerate_id(true);
    $_SESSION=['usuario_id'=>$id,'nombre'=>$u['nombre'],'roles'=>$roles,'csrf'=>bin2hex(random_bytes(32))];
    redirigir(url());
}
function fn_login($conn)
{
    $form=formulario('login',
        campo('usuario_id','ID de empleado','','number','id="login_usuario_id" min="1" required autofocus inputmode="numeric"').
        campo('clave','Contraseña','','password','id="login_clave" required autocomplete="current-password"'),
        'Ingresar','BTN BTN_BLOQUE');
    $demo='';
    // Atajos de prueba solo en el servidor de desarrollo (php -S); nunca en Apache.
    if (PHP_SAPI==='cli-server') {
        $demo='<div class="LOGIN_DEMO"><p>Usuarios de prueba <small>(solo en desarrollo)</small></p><div>';
        foreach([[1,'Administrador'],[3,'Maitre'],[7,'Mesero'],[21,'Cocinero']] as [$id,$rol])
            $demo.='<button type="button" class="BTN_DEMO" data-id="'.$id.'" data-pass="Restaurante#42">'.h($rol).'</button>';
        $demo.='</div></div>';
    }
    return '<div class="LOGIN"><h1>Ingreso del personal</h1><p class="PAGE_META">Use su ID de empleado y contraseña.</p>'.$form.$demo.'</div>';
}
