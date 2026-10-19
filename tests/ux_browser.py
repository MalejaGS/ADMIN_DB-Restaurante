"""Pruebas de UX en Chromium contra las fixtures temporales de integration.php.
Requiere Playwright y un servidor local apuntando a restaurante_test.
"""
import os
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

BASE = os.environ.get('RESTAURANTE_TEST_URL', 'http://127.0.0.1:58080/')
assert urlparse(BASE).hostname in ('127.0.0.1', 'localhost')
assert urlparse(BASE).port == 58080, 'Use el servidor temporal del puerto 58080'
CAPTURAS = Path(os.environ.get('RESTAURANTE_CAPTURAS', '/tmp/restaurante-ux-capturas'))
CAPTURAS.mkdir(parents=True, exist_ok=True)

with sync_playwright() as pw:
    browser = pw.chromium.launch(args=['--no-sandbox'])
    page = browser.new_page(viewport={'width': 1440, 'height': 1000})
    errores = []
    page.on('pageerror', lambda error: errores.append(str(error)))
    response = page.goto(BASE)
    assert response.headers.get('x-restaurante-test') == '1', 'El servidor debe apuntar a restaurante_test'
    page.screenshot(path=str(CAPTURAS / 'login.png'), full_page=True)
    page.get_by_label('ID de usuario', exact=True).fill('3')
    page.get_by_label('Contraseña', exact=True).fill('Restaurante#42')
    page.get_by_role('button', name='Mostrar contraseña', exact=True).click()
    assert page.locator('[name=clave]').get_attribute('type') == 'text'
    page.get_by_role('button', name='Ocultar contraseña', exact=True).click()
    page.get_by_role('button', name='Entrar a mi espacio').click()
    page.wait_for_selector('.ACCESOS')
    page.screenshot(path=str(CAPTURAS / 'inicio.png'), full_page=True)
    page.goto(BASE + '?opcion=realizar_pedidos&mesa_id=1')
    buscador = page.get_by_label('Buscar cliente por nombre o ID')
    buscador.fill('Cliente dos')
    page.locator('.CLIENTE_RESULTADOS button').first.click()
    assert page.locator('[name=cliente_id]').input_value() == '7'
    plus = page.locator('.CATALOGO button[data-delta="1"]').first
    plus.click()
    plus.click()
    assert '2 unidades' in page.locator('.UNIDADES_PEDIDO').inner_text()
    assert '30.000' in page.locator('.TOTAL_PEDIDO').inner_text()
    page.get_by_label('Buscar plato', exact=True).fill('sin coincidencias')
    assert page.locator('.CATALOGO .PLATO:visible').count() == 0
    assert '2 unidades' in page.locator('.UNIDADES_PEDIDO').inner_text()
    page.get_by_label('Buscar plato', exact=True).fill('')
    # Provocar validación del servidor: el formulario y sus cantidades se conservan.
    page.locator('.CLIENTE_SELECTOR summary').click()
    page.get_by_label('ID del cliente', exact=True).fill('999999')
    page.get_by_role('button', name='Registrar pedido', exact=True).click()
    page.wait_for_selector('.ERROR_FORMULARIO')
    assert 'cliente activo' in page.locator('.ERROR_FORMULARIO').inner_text()
    assert page.locator('input[data-precio]').first.input_value() == '2'
    page.get_by_label('ID del cliente', exact=True).fill('7')
    page.screenshot(path=str(CAPTURAS / 'pedido.png'), full_page=True)
    page.set_viewport_size({'width': 390, 'height': 844})
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'desbordamiento horizontal'
    page.screenshot(path=str(CAPTURAS / 'pedido-movil.png'), full_page=True)
    page.get_by_role('button', name='Registrar pedido', exact=True).click()
    page.wait_for_url('**opcion=pedido**')
    assert 'En preparación' in page.locator('#contenido').inner_text()
    page.context.clear_cookies()
    page.set_viewport_size({'width': 1440, 'height': 1000})
    page.goto(BASE)
    page.get_by_label('ID de usuario', exact=True).fill('1')
    page.get_by_label('Contraseña', exact=True).fill('Restaurante#42')
    page.get_by_role('button', name='Entrar a mi espacio').click()
    page.wait_for_selector('.ACCESOS')
    page.screenshot(path=str(CAPTURAS / 'inicio-admin.png'), full_page=True)
    page.goto(BASE + '?opcion=reportes')
    assert page.locator('.TARJETAS .TARJETA').count() == 3
    page.screenshot(path=str(CAPTURAS / 'reportes.png'), full_page=True)
    for width in (390, 320):
        page.set_viewport_size({'width': width, 'height': 844})
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'reportes desbordados'
    page.goto(BASE + '?opcion=gestion_menu')
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'menú desbordado'
    assert not errores, errores
    browser.close()
print('OK: login, mostrar clave, búsqueda de cliente, cantidades, filtros, errores sin perder datos, guardado AJAX y diseño móvil')
print('Capturas:', CAPTURAS)
