import { ApiService } from '@/modules/Services/api-service';
import { AuthService } from '@/modules/Services/auth-service';
import { CommonModule, NgIf } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { PasswordModule } from 'primeng/password';
import { ButtonModule } from 'primeng/button';
import { ToastModule } from 'primeng/toast';
import { DialogModule } from 'primeng/dialog';
import { ProgressSpinnerModule } from 'primeng/progressspinner';
import { MessageService } from 'primeng/api';
import { environment } from 'src/environments/environment';
import { Observable } from 'rxjs';
import { HttpClient, HttpHeaders } from '@angular/common/http';

@Component({
    selector: 'app-login',
    imports: [
        CommonModule,
        FormsModule,
        PasswordModule,
        ButtonModule,
        ToastModule,
        DialogModule,
        ProgressSpinnerModule,
        NgIf
    ],
    templateUrl: './login.html',
    styleUrl: './login.scss',
    standalone: true
})
export class Login implements OnInit {
    email: string = '';
    password: string = '';
    recoveryEmail: string = '';
    displayRecovery: boolean = false;
    showPassword: boolean = false;
    loading: boolean = false;

    constructor(
        private router: Router,
        private authService: AuthService,
        private apiService: ApiService,
        private http: HttpClient,
        private messageService: MessageService
    ) { }

    async ngOnInit() {
        const isAuthenticated = await this.authService.isAuthenticated();

        // Si el usuario ya está autenticado, redirigir a Home
        if (isAuthenticated) {
            this.router.navigate(['/home']);
        }
    }

    togglePasswordVisibility() {
        this.showPassword = !this.showPassword;
    }

    login2() {
        this.loading = true;
        localStorage.setItem('idUsuario', '2');
        localStorage.setItem('idRol', '2');
        localStorage.setItem('email', 'adrian.merlo.am3+20@gmail.com');
        localStorage.setItem('nombres', 'Xavier');
        localStorage.setItem('apellidos', 'Cangas');

        this.showToast('success', 'Inicio de sesión exitoso', 'Bienvenido');
        const delayMs = 1000; // 1s de espera 
        setTimeout(() => {
            this.loading = false;
            this.router.navigate(['/home']);
        }, delayMs);
    }

    login() {

        const formData = {
            email: this.email,
            password: this.password,
        };


        this.loading = true;


        this.apiService.post(`${environment.apiUrl}/controllers/login.controller.php`, formData).subscribe({
            // this.postData(payload).subscribe({
            next: (response) => {
                console.log(response);
                console.log('ingreso')
                this.authService.saveSession(response.user);
                this.loading = false;
                this.showToast('success', 'Inicio de sesión exitoso', 'Bienvenido');
                this.router.navigate(['/home']);
            },
            // error: () => {
            //     this.loading = false;
            //     this.apiService['showToast']('error', 'Inicio de sesión fallido', 'Credenciales inválidas');
            // }
            error: (err) => {
                this.loading = false;
                console.log('error')

                const msg = err?.error?.message || 'Error inesperado al iniciar sesión.';
                this.showToast('error', 'Inicio de sesión fallido', msg);

                console.error('Detalles del error:', err);
            }
        });
    }

    loginNuevo() {
        const formData = {
            email: this.email,
            password: this.password,
        };

        this.loading = true;
        console.log(formData)

        this.apiService.postLogin('controllers/login.controller.php', formData).subscribe({
            next: (response) => {
                this.authService.saveSession(response.user); // Guarda datos del usuario
                this.loading = false;
                this.apiService.showToast('success', 'Bienvenido', 'Inicio de sesión exitoso');
                setTimeout(() => {
                    this.router.navigate(['/dashboard']);
                }, 300); // espera 300ms antes de redirigir
                // this.router.navigate(['/dashboard']); // Redirección
            },
            error: (err) => {
                this.loading = false;

                const msg = err?.error?.message || 'Error inesperado al iniciar sesión.';
                this.showToast('error', 'Inicio de sesión fallido', msg);

                console.error('Detalles del error:', err);
            }
        });
    }


    sendRecoveryEmail() {
        this.loading = true;
        const data = { email: this.recoveryEmail };

        this.apiService.post('controllers/recuperarcontrasena.controller.php?op=recuperar', data).subscribe({
            next: () => {
                this.showToast('success', 'Correo enviado', 'Revisa tu bandeja de entrada');
                this.displayRecovery = false;
                this.loading = false;
            },
            error: () => {
                this.loading = false;
                this.showToast('error', 'Error', 'No se pudo enviar el correo');
            }
        });
    }

    postData(data: any): Observable<any> {
        console.log(data)
        return this.http.post<any>(`${environment.apiUrl}/controllers/login.controller.php`, data, {
            headers: new HttpHeaders({
                'Content-Type': 'application/json'
            })
        });
    }

    private showToast(severity: 'success' | 'info' | 'warn' | 'error', summary: string, detail: string): void {
        this.messageService.add({ severity, summary, detail });
    }

}
