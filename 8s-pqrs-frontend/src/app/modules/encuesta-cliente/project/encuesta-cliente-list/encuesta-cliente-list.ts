import { ApiService } from '@/modules/Services/api-service';
import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { FormArray, FormBuilder, FormControl, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { ConfirmationService, MessageService } from 'primeng/api';
import { ButtonModule } from 'primeng/button';
import { ConfirmDialogModule } from 'primeng/confirmdialog';
import { ProgressSpinnerModule } from 'primeng/progressspinner';
import { RadioButtonModule } from 'primeng/radiobutton';
import { TextareaModule } from 'primeng/textarea';
import { ToastModule } from 'primeng/toast';
import { ToolbarModule } from 'primeng/toolbar';
import { lastValueFrom } from 'rxjs';
import { ConsentimientoDialogComponent } from '../../dialogs/consentimiento-dialog-component/consentimiento-dialog-component';
import { PoliticaDialogComponent } from '../../dialogs/politica-dialog-component/politica-dialog-component';
import { DialogService } from 'primeng/dynamicdialog';
import { DialogModule } from 'primeng/dialog';
import { SelectModule } from 'primeng/select';

@Component({
    selector: 'app-encuesta-cliente-list',
    imports: [
        CommonModule,
        FormsModule,
        ReactiveFormsModule,
        ButtonModule,
        RadioButtonModule,
        ToolbarModule,
        ToastModule,
        ConfirmDialogModule,
        ProgressSpinnerModule,
        TextareaModule,
        DialogModule,
        SelectModule,
    ],
    providers: [
        DialogService,
        MessageService,
        ConfirmationService,
    ],
    templateUrl: './encuesta-cliente-list.html',
    styleUrl: './encuesta-cliente-list.scss'
})
export class EncuestaClienteList implements OnInit {
    idProgEncuesta: number = 0;
    encuestaProgramada: any;
    idCanal: any;
    plantillaEncuesta: any;
    preguntas: any[] = [];
    loading: boolean = false;
    categoriasPqrs: any[] = [];

    formEncuesta!: FormGroup;
    cargando = false;
    mostrarEncuesta: boolean = false;

    constructor(
        private fb: FormBuilder,
        private route: ActivatedRoute,
        private apiService: ApiService,
        private messageService: MessageService,
        private dialogService: DialogService,
        private confirmationService: ConfirmationService,
        private router: Router,
    ) { }

    // async ngOnInit(): Promise<void> {
    //     this.idProgEncuesta = Number(this.route.snapshot.paramMap.get('id')) || 0;

    //     if (this.idProgEncuesta > 0) {
    //         const acepto = await this.mostrarConsentimiento();
    //         if (acepto) {
    //             await this.cargarDatos();
    //         }
    //     }
        
    // }
async ngOnInit(): Promise<void> {
  try {
    // 1) Leer el segmento :id (puede ser número o token)
    const raw = this.route.snapshot.paramMap.get('id') ?? '';

    // 2) Resolver a idProgEncuesta
    this.idProgEncuesta = await this.resolverIdProgDesdeParam(raw);

    if (this.idProgEncuesta > 0) {
      const acepto = await this.mostrarConsentimiento();
      if (acepto) {
        await this.cargarDatos();
      }
    } else {
      // Manejo cuando no se pudo resolver
      this.messageService.add({
        severity: 'error',
        summary: 'Enlace inválido',
        detail: 'No se pudo determinar la encuesta. El enlace es inválido o ha expirado.',
        life: 6000
      });
    }
  } catch (e: any) {
    this.messageService.add({
      severity: 'error',
      summary: 'Error',
      detail: e?.message || 'No se pudo cargar la encuesta.',
      life: 6000
    });
  }
}

    async cargarDatos() {
        this.cargando = true;
        try {
            // Datos generales de la encuesta programada
            const encuestaResp: any = await (
                await this.apiService.get<any[]>(`controllers/encuestasprogramadas.controller.php?op=uno&idProgEncuesta=${this.idProgEncuesta}`)
            ).toPromise();
            this.encuestaProgramada = encuestaResp;
            this.idCanal = this.encuestaProgramada.idCanal;

            const resp$ = await this.apiService.get<any[]>(`controllers/categoriasPqrs.controller.php?op=todos&idCanal=${encodeURIComponent(String(this.idCanal))}`);
            const categorias = await lastValueFrom(resp$);

            this.categoriasPqrs = Array.isArray(categorias) ? categorias : [];
            console.log(this.categoriasPqrs)

            // Plantilla de encuesta
            const plantillaResp: any = await (
                await this.apiService.get<any[]>(`controllers/encuesta.controller.php?op=uno&idEncuesta=${this.encuestaProgramada.idEncuesta}`)
            ).toPromise();
            this.plantillaEncuesta = plantillaResp;
            console.log(this.plantillaEncuesta)

            // Preguntas + opciones
            const preguntasResp = await this.apiService.get<any[]>(
                `/controllers/pregunta.controller.php?op=listarPreguntas&idEncuesta=${this.plantillaEncuesta.idEncuesta}`
            );
            const data: any = await lastValueFrom(preguntasResp);

            this.preguntas = data.map((e: any) => {
                let tipoP = 0;
                switch (e.tipo) {
                    case 'ESCALA_1_10':
                        tipoP = 1; // botones 1..10
                        break;
                    case 'SI_NO':
                        tipoP = 2; // radio
                        break;
                    case 'SELECCION':
                        tipoP = 3; // botones (ej. 0..10 NPS u otras)
                        break;
                }
                return { ...e, tipoP };
            });

            console.log(this.preguntas)
            this.generarFormulario();
        } catch (error) {
            console.error(error);
        } finally {
            this.cargando = false;
        }
    }

    /** --------- HELPERS DE ÍNDICE POR ID --------- */
    private indexById(idPregunta: number): number {
        return this.preguntas.findIndex(p => p.idPregunta === idPregunta);
    }

    /** --------- CONTROLES (valor / comentario) --------- */
    getRespuestaControl(idPregunta: number): FormControl {
        const idx = this.indexById(idPregunta);
        const grupo = this.respuestasFormArray.at(idx) as FormGroup;
        return grupo.get('valor') as FormControl;
    }

    getComentarioControl(idPregunta: number): FormControl {
        const idx = this.indexById(idPregunta);
        const grupo = this.respuestasFormArray.at(idx) as FormGroup;
        return grupo.get('comentario') as FormControl;
    }

    // --- Helpers ---
    private toBool(v: any): boolean {

        return v === 1 || v === true || v === '1';
    }

    // --- Getters nuevos ---
    getCategoriaControl(idPregunta: number): FormControl {
        const idx = this.indexById(idPregunta);
        const grupo = this.respuestasFormArray.at(idx) as FormGroup;
        return grupo.get('categoriaId') as FormControl;
    }

    /** --------- FORM --------- */
    generarFormulario() {
        this.formEncuesta = this.fb.group({
            respuestas: this.fb.array(
                this.preguntas.map(p =>
                    this.fb.group({
                        idPregunta: [p.idPregunta, Validators.required],
                        valor: [null, Validators.required],
                        categoriaId: [null],
                        comentario: [''],
                        // NUEVOS CAMPOS para enviar al backend:
                        idOpcion: [null],                 // ← se setea al elegir opción
                        valorTexto: [null],               // ← si usas texto en opción
                        generaPqr: [0],                   // ← flag por opción (0/1)

                        permitirComentario: [0]           // ← flag por opción (0/1) para referencia
                    })
                )
            )
        });
    }

    get respuestasFormArray(): FormArray {
        return this.formEncuesta.get('respuestas') as FormArray;
    }

    //// inicio

    /** --------- SELECCIÓN PARA BOTONES (tipoP 1 y 3) --------- */
    seleccionarRespuesta(pregunta: any, opcion: any) {
        const idPregunta: number = pregunta.idPregunta;
        const valor: number = opcion.valorNumerico;
        const idx = this.indexById(idPregunta);
        if (idx === -1) {
            console.warn(`No se encontró la pregunta con id ${idPregunta}`);
            return;
        }

        const grupo = this.respuestasFormArray.at(idx) as FormGroup;
        // Setear el valor elegido
        // grupo.get('valor')?.setValue(valor);

        //Cambio para guardar los valores
        // Setea valor e info de la opción
        grupo.patchValue({
            valor: opcion.valorNumerico ?? null,
            idOpcion: opcion.idOpcion ?? null,
            valorTexto: opcion.etiqueta ?? null,
            generaPqr: this.toBool(opcion.generaPqr) ? 1 : 0,
            permitirComentario: this.toBool(opcion.permitirComentario) ? 1 : 0
        });
        //fin cambio

        // Aplicar reglas según la opción seleccionada
        this.aplicarReglasPostSeleccion(pregunta, opcion, grupo);
    }

    /** --------- REGLAS por opción seleccionada --------- */
    private aplicarReglasPostSeleccion(pregunta: any, opcion: any, grupo?: FormGroup) {
        const idx = this.indexById(pregunta.idPregunta);
        const g = grupo ?? (this.respuestasFormArray.at(idx) as FormGroup);

        // 1) generaPqr => mostrar dropdown de categorías y hacerlo requerido
        const requiereCategoria = this.toBool(opcion.generaPqr);
        pregunta.requiereCategoria = requiereCategoria;

        const catCtrl = g.get('categoriaId') as FormControl;
        if (requiereCategoria) {
            catCtrl.setValidators([Validators.required]);
        } else {
            catCtrl.clearValidators();
            catCtrl.setValue(null);
        }
        catCtrl.updateValueAndValidity({ emitEvent: false });

        // 2) permitirComentario => mostrar textarea y hacerlo requerido
        const requiereComentario = this.toBool(opcion.requiereComentario);
        pregunta.requiereComentario = requiereComentario;

        const comCtrl = g.get('comentario') as FormControl;
        if (requiereComentario) {
            comCtrl.setValidators([Validators.required, Validators.maxLength(1000)]);
        } else {
            comCtrl.clearValidators();
            comCtrl.setValue('');
        }
        comCtrl.updateValueAndValidity({ emitEvent: false });
    }

    /** --------- RADIO: hook para aplicar reglas cuando cambia --------- */
    onRadioClick(pregunta: any, opcion: any) {
        const idx = this.indexById(pregunta.idPregunta);
        const grupo = this.respuestasFormArray.at(idx) as FormGroup;

        // El p-radioButton ya setea el valor en el formControl; reforzamos y aplicamos reglas
        // grupo.get('valor')?.setValue(opcion.valorNumerico);

        //cambio para agregar los valores
        grupo.patchValue({
            valor: opcion.valorNumerico ?? null,
            idOpcion: opcion.idOpcion ?? null,
            valorTexto: opcion.etiqueta ?? null,
            generaPqr: this.toBool(opcion.generaPqr) ? 1 : 0,
            permitirComentario: this.toBool(opcion.permitirComentario) ? 1 : 0
        });

        this.aplicarReglasPostSeleccion(pregunta, opcion, grupo);
    }

    ///

    /** --------- CHEQUEO DE SELECCIÓN --------- */
    esSeleccionado(idPregunta: number, valor: number): boolean {
        const idx = this.indexById(idPregunta);
        if (idx === -1) return false;
        const control = (this.respuestasFormArray.at(idx) as FormGroup).get('valor');
        return control?.value === valor;
    }

    /** --------- trackBy para estabilidad del DOM --------- */
    trackPregunta = (_: number, p: any) => p.idPregunta;
    trackOpcion = (_: number, o: any) => o.idOpcion;

    /** --------- CONSENTIMIENTO --------- */
    async mostrarConsentimiento(): Promise<boolean> {
        return new Promise<boolean>((resolve) => {
            const ref = this.dialogService.open(ConsentimientoDialogComponent, {
                header: 'Política de Datos Personales',
                width: '70vw',
                styleClass: 'dialog-fullscreen',
                modal: true,
                dismissableMask: false,
                closable: false,
                data: {
                    verPolitica: () => {
                        this.dialogService.open(PoliticaDialogComponent, {
                            header: 'Política completa',
                            width: '80vw',
                            modal: true,
                            dismissableMask: true,
                            closable: true,
                            styleClass: 'dialog-superpuesto'
                        });
                    }
                }
            });

            ref.onClose.subscribe(async (result) => {
                if (result?.aceptado === true) {
                    await this.registrarConsentimiento(true);
                    this.mostrarEncuesta = true;
                    resolve(true);
                } else if (result?.aceptado === false) {
                    await this.registrarConsentimiento(false);
                    this.mostrarEncuesta = false;
                    this.messageService.add({
                        severity: 'info',
                        summary: 'Gracias',
                        detail: 'La encuesta no se completará.',
                    });
                    resolve(true);
                }
            });
        });
    }

    async registrarConsentimiento(acepta: boolean) {
        if (!this.idProgEncuesta || this.idProgEncuesta <= 0) {
            this.messageService.add({ severity: 'warn', summary: 'Falta información', detail: 'No se pudo determinar el idProgEncuesta.' });
            return;
        }

        const formData = new FormData();
        formData.append('idProgEncuesta', String(this.idProgEncuesta));
        // El backend espera 'acepta' (1|0), no 'consentimientoAceptado'
        formData.append('acepta', acepta ? '1' : '0');
        // La IP es mejor capturarla en el backend: $_SERVER['REMOTE_ADDR'] / X-Forwarded-For
        // Si igual quieres enviar algo, deja un placeholder:

        // formData.append('ip', '0.0.0.0');
        // formData.append('userAgent', navigator?.userAgent || '');

        try {
            // Usa tu ApiService (que devuelve Observable envuelto en Promise)
            const resp: any = await lastValueFrom(
                this.apiService.post('controllers/encuestasprogramadas.controller.php?op=registrar_consentimiento', formData)
            );

            if (resp?.success === true) {
                this.messageService.add({
                    severity: 'success',
                    summary: 'Consentimiento',
                    detail: acepta ? 'Aprobado correctamente.' : 'Rechazado correctamente.'
                });
            } else {
                // El backend puede devolver {success:false, error:"..."} o {message:"..."}
                const detalle = resp?.error || resp?.message || 'No se pudo registrar el consentimiento';
                this.messageService.add({ severity: 'warn', summary: 'Atención', detail: detalle });
            }
        } catch (err: any) {
            const detalle = err?.error?.error || err?.error?.message || err?.message || 'Error al registrar el consentimiento';
            this.messageService.add({ severity: 'error', summary: 'Error', detail: detalle });
        }
    }

    /** --------- ENVÍO --------- */
    // async enviarEncuesta() {
    //     if (this.formEncuesta.invalid) {
    //         this.apiService.showToast('error', 'Error', 'Por favor responde todas las preguntas requeridas');
    //         return;
    //     }

    //     const payload = {
    //         idProgEncuesta: this.idProgEncuesta,
    //         idEncuesta: this.encuestaProgramada.idEncuesta,
    //         respuestas: this.formEncuesta.value.respuestas,
    //     };

    //     try {
    //         const resp = await (
    //             await this.apiService.post(`respuestas.controller.php?op=guardarRespuestas`, payload)
    //         ).toPromise();

    //         if (resp?.success) {
    //             this.messageService.add({ severity: 'success', summary: 'Enviado', detail: '¡Encuesta enviada correctamente!' });
    //         } else {
    //             this.messageService.add({ severity: 'warn', summary: 'Atención', detail: resp?.message || 'No se pudo guardar la encuesta' });
    //         }
    //     } catch (error) {
    //         console.error(error);
    //         this.messageService.add({ severity: 'error', summary: 'Error', detail: 'Error al guardar la encuesta' });
    //     }
    // }

    cancelarEncuesta() {
        this.confirmationService.confirm({
            header: 'Cancelar encuesta',
            message: '¿Seguro que deseas cancelar? Se perderán las respuestas no guardadas.',
            icon: 'pi pi-exclamation-triangle',
            acceptLabel: 'Sí, salir',
            rejectLabel: 'Seguir contestando',
            accept: () => {
                // Opcional: this.formEncuesta?.reset();
                // Redirige al Home (ajusta la ruta si tu Home es otra)
                this.router.navigate(['/home']); // o this.router.navigate(['/']);
            }
        });
    }

    async enviarEncuesta() {
        if (this.formEncuesta.invalid) {
            this.apiService.showToast('error', 'Error', 'Por favor responde todas las preguntas requeridas');
            return;
        }

        const payload = {
            idProgEncuesta: this.idProgEncuesta,
            idEncuesta: this.encuestaProgramada.idEncuesta,
            respuestas: this.formEncuesta.value.respuestas,
        };

        this.confirmationService.confirm({
            header: 'Confirmar envío',
            message: '¿Deseas enviar la encuesta ahora? No podrás modificar tus respuestas después.',
            icon: 'pi pi-question-circle',
            acceptLabel: 'Sí, enviar',
            rejectLabel: 'Cancelar',
            accept: () => this.guardarEncuesta(payload),
            // Opcional: manejar rechazo
            // reject: () => { ... }
        });
    }

    // private async guardarEncuesta(payload: any) {
    //     try {
    //         const resp = await (
    //             await this.apiService.post(`respuestas.controller.php?op=guardarRespuestas`, payload)
    //         ).toPromise();

    //         if (resp?.success) {
    //             this.messageService.add({ severity: 'success', summary: 'Enviado', detail: '¡Encuesta enviada correctamente!' });
    //             // opcional: this.formEncuesta.disable(); this.mostrarEncuesta = false; etc.
    //         } else {
    //             this.messageService.add({ severity: 'warn', summary: 'Atención', detail: resp?.message || 'No se pudo guardar la encuesta' });
    //         }
    //     } catch (error) {
    //         console.error(error);
    //         this.messageService.add({ severity: 'error', summary: 'Error', detail: 'Error al guardar la encuesta' });
    //     }
    // }

    private async guardarRespuestasCliente(): Promise<void> {
        const respuestas = (this.formEncuesta.get('respuestas') as FormArray).value as any[];
        console.log(respuestas);

        // Prepara un array de promesas para insertar cada respuesta
        const inserts = respuestas.map(r => {
            const formData = new FormData();
            formData.append('idProgEncuesta', String(this.idProgEncuesta));
            formData.append('idPregunta', String(r.idPregunta));

            if (r.idOpcion != null) formData.append('idOpcion', String(r.idOpcion));
            if (r.valor != null) formData.append('valorNumerico', String(r.valor));
            if (r.valorTexto != null) formData.append('valorTexto', String(r.valorTexto));
            if (r.comentario) formData.append('comentario', String(r.comentario));
            if (r.generaPqr != null) formData.append('generaPqr', String(r.generaPqr));
            if (r.categoriaId != null) formData.append('idCategoria', String(r.categoriaId));
            formData.append('estado', '1');

            // POST por respuesta
            return lastValueFrom(
                this.apiService.post('controllers/respuestascliente.controller.php?op=insertar', formData)
            );
        });

        // Ejecuta todos los inserts en paralelo
        await Promise.all(inserts);
    }

    // private async guardarEncuesta(_payload: any) {
    //     try {
    //         // Primero guarda respuestas individuales
    //         await this.guardarRespuestasCliente();

    //         // (Opcional) Si además tienes un endpoint para “cerrar” la encuesta, llámalo aquí

    //         this.messageService.add({ severity: 'success', summary: 'Enviado', detail: '¡Encuesta enviada correctamente!' });
    //         // opcional: this.formEncuesta.disable(); this.mostrarEncuesta = false;
    //     } catch (error) {
    //         console.error(error);
    //         this.messageService.add({ severity: 'error', summary: 'Error', detail: 'Error al guardar la encuesta' });
    //     }
    // }


    private buildLoteDesdeFormulario(): any {
        const respuestasFA = this.formEncuesta.get('respuestas') as FormArray;
        const raw = respuestasFA.value as any[];

        // Normaliza los nombres al backend
        const respuestas = raw.map(r => ({
            idPregunta: r.idPregunta,
            idOpcion: r.idOpcion ?? null,
            valorNumerico: r.valor ?? null,
            valorTexto: r.valorTexto ?? null,
            comentario: r.comentario ?? null,
            generaPqr: r.generaPqr ?? null,
            idCategoria: r.categoriaId ?? null,
            estado: 1
        }));

        return {
            idProgEncuesta: this.idProgEncuesta,
            respuestas,
            atomic: true // si quieres rollback total si falla alguna
        };
    }

    private async guardarEncuesta(_payload: any) {
        try {
            const lote = this.buildLoteDesdeFormulario();

            const resp = await (
                await this.apiService.post(`controllers/respuestascliente.controller.php?op=guardar_lote`, lote)
            ).toPromise();

            if (resp?.success) {
                this.messageService.add({ severity: 'success', summary: 'Enviado', detail: resp.message || '¡Encuesta enviada correctamente!' });
                // opcional: this.formEncuesta.disable(); this.mostrarEncuesta = false; this.router.navigate(['/home']);
            } else {
                this.messageService.add({ severity: 'warn', summary: 'Atención', detail: resp?.message || 'No se pudo guardar la encuesta' });
                if (resp?.errores?.length) {
                    console.warn('Errores por respuesta:', resp.errores);
                }
            }
        } catch (error) {
            console.error(error);
            this.messageService.add({ severity: 'error', summary: 'Error', detail: 'Error al guardar la encuesta' });
        }
    }

    private debeGenerarPqrs(): boolean {
        const respuestas = (this.formEncuesta.get('respuestas') as FormArray).value as any[];
        // Genera PQR si al menos una respuesta marcó generaPqr = 1
        return respuestas.some(r => Number(r?.generaPqr) === 1);
    }

//am -26092025
/** 
 * Si "raw" es un número válido, lo devuelve.
 * Si es un token, llama al backend para traducirlo a idProgEncuesta.
 */
private async resolverIdProgDesdeParam(raw: string): Promise<number> {
  const maybeNum = Number(raw);
  if (Number.isFinite(maybeNum) && maybeNum > 0) {
    return maybeNum; // era un id normal, como /enc/50
  }

  // era un token → pedir al backend que lo resuelva
  try {
    const resp: any = await lastValueFrom(
      this.apiService.get(`/controllers/atenciones.controller.php?op=resolver_token&t=${encodeURIComponent(raw)}`)
    );
    if (resp?.success && resp?.idProgEncuesta) {
      return Number(resp.idProgEncuesta);
    }
  } catch (err) {
    // opcional: log o toast
  }
  return 0;
}



}
