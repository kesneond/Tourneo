import { ref } from 'vue';
import { defineStore } from 'pinia';
import api from '../axios'; // Importujeme ten soubor z kroku 1

export const useTournamentStore = defineStore('tournament', () => {
    // --- STAV (Data) ---
    const tournaments = ref([]);
    const currentTournament = ref(null);
    const loading = ref(false);
    const error = ref(null);

    // --- AKCE (Funkce) ---
    
    // 1. Načíst všechny turnaje
    const fetchTournaments = async () => {
        loading.value = true;
        try {
            const response = await api.get('/tournaments');
            tournaments.value = response.data;
        } catch (err) {
            console.error(err);
            error.value = 'Nepodařilo se načíst turnaje.';
        } finally {
            loading.value = false;
        }
    };

    // 2. Vytvořit nový turnaj
    const createTournament = async (tournamentData) => {
        try {
            await api.post('/tournaments', tournamentData);
            await fetchTournaments(); // Po vytvoření hned aktualizujeme seznam
        } catch (err) {
            throw err; // Chybu pošleme dál, ať ji zachytí formulář
        }
    };

    const deleteTournament = async (id) => {
        if (!confirm('Opravdu chcete smazat tento turnaj? Tato akce je nevratná.')) return;
        
        try {
            await api.delete(`/tournaments/${id}`);
            await fetchTournaments(); // Obnovit seznam po smazání
        } catch (err) {
            alert('Chyba při mazání: ' + err.message);
        }
    };

    // 3. Načíst detail (použijeme později)
    const fetchTournamentDetail = async (id) => {
        loading.value = true;
        try {
            const response = await api.get(`/tournaments/${id}`);
            currentTournament.value = response.data;
        } catch (err) {
            error.value = 'Turnaj nenalezen';
        } finally {
            loading.value = false;
        }
    };

    // 4. Přidat hráče (použijeme později)
    const addPlayer = async (tournamentId, name) => {
        await api.post(`/tournaments/${tournamentId}/players`, { name });
        await fetchTournamentDetail(tournamentId);
    };

    // 5. Rozlosovat (použijeme později)
    const generateMatches = async (tournamentId) => {
        try {
            await api.post(`/tournaments/${tournamentId}/generate`);
            await fetchTournamentDetail(tournamentId);
        } catch (err) {
            throw err;
        }
    };

    // 6. Aktualizovat nastavení turnaje (např. počet stolů)
    const updateTournament = async (id, data) => {
        try {
            const response = await api.put(`/tournaments/${id}`, data);
            // Aktualizujeme lokální data, ať se to hned projeví
            if (currentTournament.value && currentTournament.value.id === id) {
                currentTournament.value = { ...currentTournament.value, ...response.data };
            }
        } catch (err) {
            console.error(err);
            alert('Nepodařilo se uložit nastavení.');
        }
    };

    // Musíme vrátit všechno, co chceme používat v komponentách
    return {
        tournaments,
        currentTournament,
        loading,
        error,
        fetchTournaments,
        createTournament,
        deleteTournament,
        fetchTournamentDetail,
        addPlayer,
        generateMatches,
        updateTournament
    };
});