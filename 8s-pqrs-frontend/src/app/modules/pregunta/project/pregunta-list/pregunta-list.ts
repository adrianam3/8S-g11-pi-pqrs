import { ApiService } from '@/modules/Services/api-service';
import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { MessageService } from 'primeng/api';
import { ButtonModule } from 'primeng/button';
import { DialogModule } from 'primeng/dialog';
import { IconFieldModule } from 'primeng/iconfield';
import { InputIconModule } from 'primeng/inputicon';
import { InputTextModule } from 'primeng/inputtext';
import { PasswordModule } from 'primeng/password';
import { ProgressSpinnerModule } from 'primeng/progressspinner';
import { Table, TableModule } from 'primeng/table';
import { ToastModule } from 'primeng/toast';
import { lastValueFrom } from 'rxjs';

@Component({
    selector: 'app-pregunta-list',
    imports: [
        CommonModule,
        RouterModule,
        FormsModule,
        RouterModule,
        PasswordModule,
        ButtonModule,
        ToastModule,
        DialogModule,
        ProgressSpinnerModule,
        TableModule,
        IconFieldModule,
        InputIconModule,
        InputTextModule,
    ],
    templateUrl: './pregunta-list.html',
    styleUrl: './pregunta-list.scss'
})
export class PreguntaList implements OnInit {
    preguntas: any[] = [];
    loading = true;
    private idEncuesta: any;
    readonly endpoint = 'controllers/pregunta.controller.php';

    constructor(
        private apiService: ApiService,
        private activatedRoute: ActivatedRoute,
        private router: Router,
    ) { }

    ngOnInit(): void {
        try {
            this.loading = true;
            this.activatedRoute.params.subscribe(async (params) => {
                this.idEncuesta = params['id'];
                if (this.idEncuesta) {
                    await this.cargarPreguntasPorEncuesta(this.idEncuesta);
                } else {
                    this.apiService.showToast('error', 'Error', 'No se proporcionó un ID de encuesta');
                }
            });
        } catch (error) {
            this.apiService.showToast('error', 'Error', 'Error al cargar las encuestas');
            this.loading = false;
        }
    }

    onGlobalFilter(table: Table, event: Event) {
        table.filterGlobal((event.target as HTMLInputElement).value, 'contains');
    }

    volver() {
         this.router.navigate(['/encuestas']);
    }

    async cargarPreguntasPorEncuesta(idEncuesta: number): Promise<void> {
        this.loading = true;

        try {
            const preguntasObs = await this.apiService.get<any[]>(
                `${this.endpoint}?op=listarPreguntas&idEncuesta=${idEncuesta}`
            );
            const data = await lastValueFrom(preguntasObs);

            this.preguntas = data.map((p) => ({
                ...p,
                permiteComentario: p.permiteComentario == 1,
                generaPqr: p.generaPqr == 1,
                esNps: p.esNps == 1,
                opciones: p.opciones || [], // Espera que el backend ya retorne las opciones relacionadas
            }));
        } catch (error) {
            console.error('Error al cargar preguntas', error);
            this.apiService.showToast(
                'error',
                'Error al obtener las preguntas de la encuesta.',
                'Error'
            );
        } finally {
            this.loading = false;
        }
    }

    editarPregunta(pregunta: any): void {
        // Lógica para abrir un modal o navegar a página de edición
        console.log('Editar pregunta:', pregunta);
    }

    async eliminarPregunta(idPregunta: number): Promise<void> {
        try {
            const form = new FormData();
            form.append('idPregunta', idPregunta.toString());

            const responseObs = await this.apiService.post<any>(
                `${this.endpoint}?op=desactivarPregunta`,
                form
            );
            await lastValueFrom(responseObs);

            this.apiService.showToast(
                'success',
                'Pregunta desactivada correctamente.',
                'Eliminado'
            );

            this.preguntas = this.preguntas.filter(p => p.idPregunta !== idPregunta);
        } catch (error) {
            console.error('Error al eliminar pregunta', error);
            this.apiService.showToast(
                'error',
                'No se pudo desactivar la pregunta.',
                'Error'
            );
        }
    }

    editarOpcion(opcion: any): void {
        // Lógica para abrir un modal o inline editor
        console.log('Editar opción:', opcion);
    }

    async eliminarOpcion(idOpcion: number): Promise<void> {
        try {
            const form = new FormData();
            form.append('idOpcion', idOpcion.toString());

            const responseObs = await this.apiService.post<any>(
                `controllers/opcion.controller.php?op=desactivarOpcion`,
                form
            );
            await lastValueFrom(responseObs);

            this.apiService.showToast(
                'success',
                'Opción desactivada correctamente.',
                'Eliminado'
            );

            // Elimina la opción del arreglo local
            for (let p of this.preguntas) {
                p.opciones = p.opciones?.filter((o: any) => o.idOpcion !== idOpcion);
            }
        } catch (error) {
            console.error('Error al eliminar opción', error);
            this.apiService.showToast(
                'error',
                'No se pudo desactivar la opción.',
                'Error'
            );
        }
    }
}
