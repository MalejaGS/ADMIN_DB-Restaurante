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
    $campos=campo('usuario_id','ID de usuario','','number','min="1" required autocomplete="username" placeholder="Ingresa tu ID"').'<label>Contraseña<span class="CLAVE_CONTROL"><input type="password" name="clave" aria-label="Contraseña" required autocomplete="current-password" placeholder="Tu contraseña"><button type="button" class="VER_CLAVE" aria-label="Mostrar contraseña">Mostrar</button></span></label>';
    return '<div class="ACCESO"><section class="ACCESO_PRESENTACION">'.marca().'<div><span class="ETIQUETA">Menos vueltas. Mejor servicio.</span><h2>El ritmo de tu cocina,<br>en un solo lugar.</h2><p>Del primer plato a la última mesa. Organiza el servicio y mantén a tu equipo conectado.</p></div><span class="ACCESO_FOOT">Universidad Javeriana · Bases de datos</span></section><section class="ACCESO_PANEL"><div class="ACCESO_FORM"><span class="ETIQUETA">Bienvenido a Urbano</span><h1>Iniciar sesión</h1><p>Entra a tu espacio de trabajo para comenzar.</p>'.formulario('login',$campos,'Entrar a mi espacio').'<p class="AYUDA">Usa el ID y la contraseña asignados a tu cuenta. Si no los recuerdas, consulta con el administrador.</p></div></section></div>';
}
