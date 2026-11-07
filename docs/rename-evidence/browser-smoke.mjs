import {chromium} from '../../node_modules/playwright/index.mjs';
const browser=await chromium.launch({headless:true});
try {
 const page=await browser.newPage();
 await page.goto('http://localhost:18000/');
 console.log('Portal title:',await page.title());
 await page.getByRole('link',{name:'Iniciar sesión',exact:true}).click();
 await page.waitForURL(u=>u.origin==='http://localhost:8080');
 console.log('Keycloak login form:',await page.locator('input[name="username"]').isVisible(),await page.locator('input[name="password"]').isVisible());
 await page.goto('http://localhost:8000/login');
 console.log('Paz local password form:',await page.locator('input[name="password"]').isVisible());
 await page.getByRole('link',{name:'Iniciar sesión con AAUD OAUTH'}).click();
 await page.waitForURL(u=>u.origin==='http://localhost:8080');
 console.log('Paz OIDC reaches Keycloak:',await page.locator('input[name="username"]').isVisible());
}finally{await browser.close();}
