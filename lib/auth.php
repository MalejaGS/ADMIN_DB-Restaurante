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
    return '
    <div class="LOGIN_CONTAINER">
        <div class="LOGIN_HEADER">
            <h1>Acceso al Sistema Operativo</h1>
            <p>Seleccione su perfil de servicio o ingrese sus credenciales de empleado</p>
        </div>
        '.formulario('login',
            campo('usuario_id','ID de Empleado','','number','id="login_usuario_id" min="1" required placeholder="Ej: 1, 3, 7, 21"') .
            campo('clave','Contraseña','','password','id="login_clave" required autocomplete="current-password" placeholder="••••••••"')
        , 'Ingresar al Restaurante').'
        <div class="LOGIN_DEMO_CARD">
            <span class="DEMO_TITULO">⚡ Perfiles de Prueba Rápida (Flujos de Usuario):</span>
            <div class="DEMO_BOTONES">
                <button type="button" class="BTN_DEMO" data-id="1" data-pass="Restaurante#42">👑 Administrador (ID: 1)</button>
                <button type="button" class="BTN_DEMO" data-id="3" data-pass="Restaurante#42">🛎️ Maitre (ID: 3)</button>
                <button type="button" class="BTN_DEMO" data-id="7" data-pass="Restaurante#42">🍽️ Mesero (ID: 7)</button>
                <button type="button" class="BTN_DEMO" data-id="21" data-pass="Restaurante#42">👨‍🍳 Cocinero (ID: 21)</button>
            </div>
            <p class="DEMO_HINT">Haga clic en cualquiera de los perfiles para autocompletar las credenciales al instante.</p>
        </div>
    </div>';
}
