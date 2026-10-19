"""Pruebas HTTP contra la instancia temporal; usar después de integration.php."""
import datetime
import http.cookiejar
import os
import re
import urllib.request
import urllib.parse
import urllib.error

BASE = os.environ.get('RESTAURANTE_TEST_URL', 'http://127.0.0.1:58080/')
assert urllib.parse.urlparse(BASE).hostname in ('127.0.0.1', 'localhost'), 'Solo servidor de prueba local'

class Navegador:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
    def page(self, path='', data=None):
        req = urllib.request.Request(BASE + path, data=urllib.parse.urlencode(data).encode() if data is not None else None)
        try:
            with self.opener.open(req) as r:
                return r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()
    def login(self, uid):
        code, html = self.page()
        csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)
        code, html = self.page(data={'accion': 'login', 'csrf': csrf, 'usuario_id': uid, 'clave': 'Restaurante#42'})
        assert code == 200 and 'Opciones del programa' in html, (code, html)
        return html

def ok(value, msg):
    assert value, msg
    print('OK:', msg)

anon = Navegador()
code, html = anon.page('?opcion=reportes')
ok(code == 200 and 'Iniciar sesión' in html and '<h1>Reportes' not in html, 'sin sesión no se exponen reportes')
code, html = anon.page(data={'accion': 'login', 'csrf': 'incorrecto', 'usuario_id': 1, 'clave': 'Restaurante#42'})
ok(code == 403, 'CSRF inválido rechazado')
admin = Navegador()
html = admin.login(1)
ok('Actualizar Menú' in html, 'login administrador con clave SHA-256')
for path in ('?opcion=gestion_menu', '?opcion=reportes', '?opcion=reservas', '?opcion=clientes', '?opcion=desplegar_menu', '?opcion=realizar_pedidos'):
    code, html = admin.page(path)
    ok(code == 200, 'pantalla administrador ' + path)
code, html = admin.page('?opcion=pg_conectar')
ok(code == 404, 'despacho restringido a rutas permitidas')
mesero = Navegador()
html = mesero.login(3)
code, html = mesero.page('?opcion=gestion_menu')
ok(code == 403, 'mesero no abre administración')
code, html = mesero.page('?opcion=realizar_pedidos&mesa_id=1')
ok(code == 200 and 'TOTAL_PEDIDO' in html and 'cantidad[' in html, 'formulario real con cantidades y total')
# Un POST autorizado por CSRF pero no por rol tampoco debe modificar nada.
csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)
code, _ = mesero.page(data={'accion': 'categoria_guardar', 'csrf': csrf, 'token': 'b' * 48, 'nombre': 'No permitido'})
ok(code == 403, 'permisos aplicados también a POST')
maitre = Navegador()
maitre.login(2)
code, html = maitre.page('?opcion=reservas')
ok(code == 200 and 'datetime-local' in html, 'formulario de reserva para maitre')
futuro = (datetime.date.today() + datetime.timedelta(days=365)).isoformat()
code, html = maitre.page('?opcion=reservas&inicio_consulta=' + futuro + 'T12%3A00&personas_consulta=2')
ok(code == 200 and 'Mesa disponible' in html, 'consulta de disponibilidad por fecha, hora y personas')
cook = Navegador()
cook.login(4)
code, html = cook.page('?opcion=cocina')
ok(code == 200 and 'Preparación de platos' in html, 'pantalla de cocina')
code, html = admin.page()
csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)
code, html = admin.page(data={'accion': 'logout', 'csrf': csrf})
ok(code == 200 and 'Iniciar sesión' in html, 'cerrar sesión')
html = admin.login(1)
ok('Opciones del programa' in html, 'segundo login con contraseña migrada a password_hash')
print('PRUEBAS HTTP COMPLETADAS')
