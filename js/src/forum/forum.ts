// Eagerly import forum modules so they are registered in the module
// registry (chunked bundles resolve them via flarum.reg).
import '../common/common';

import './components/WaterfallPage';
import './components/WaterfallGrid';
import './components/WaterfallCard';
import './components/Lightbox';
import './components/WaterfallUploadModal';
