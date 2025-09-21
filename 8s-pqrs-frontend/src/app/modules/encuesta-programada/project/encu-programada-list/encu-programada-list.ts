import { ApiService } from '@/modules/Services/api-service';
import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { TableModule } from 'primeng/table';
import { ToastModule } from 'primeng/toast';
import { lastValueFrom } from 'rxjs';

@Component({
    selector: 'app-encu-programada-list',
    imports: [
        CommonModule,
        FormsModule,
        RouterModule,
        TableModule,
        ButtonModule,
        ToastModule,
        InputTextModule,
        // IconFieldModule,
        // InputIconModule,
    ],
    templateUrl: './encu-programada-list.html',
    styleUrl: './encu-programada-list.scss'
})
export class EncuProgramadaList implements OnInit {
    encuestas: any[] = [];
    public loading: boolean = false;
    private encuestaPrgApi = `/controllers/encuestasprogramadas.controller.php?op=`;


    constructor(private apiService: ApiService) { }


    ngOnInit(): void {
        this.loadEncuestas();
    }

    async loadEncuestas() {
        this.loading = true;

        try {
            const encuestasObs = await this.apiService.get<any[]>(this.encuestaPrgApi + 'todos');
            const data = await lastValueFrom(encuestasObs);
            console.log('data', data);

            this.encuestas = data.map(e => ({
                ...e,
                activaTexto: e.activa === 1 ? 'Activa' : 'Inactiva'
            }));
            console.log('encuestas', this.encuestas);

        } catch (error) {
            console.error('Error al cargar encuestas', error);
            this.apiService.showToast('error', 'Error al obtener las encuestas.', 'Error');
        } finally {
            this.loading = false;
        }
    }


    reenviarEncuesta(id: number): void {
        // this.apiService.reenviarEncuesta(id).subscribe(res => {
        //     console.log('Encuesta reenviada:', res);
        //     this.obtenerEncuestas();
        // });
    }
}
