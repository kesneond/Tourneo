import axios from 'axios';

const apiClient = axios.create({
    // Laravel Sail běží na portu 80, takže API je dostupné přímo na localhost/api
    baseURL: 'http://localhost:80/api',
    
    // Povolíme odesílání cookies (důležité pro CORS a Session)
    withCredentials: true, 
    
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
});

export default apiClient;